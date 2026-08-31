<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyPersonalDocument;
use App\Models\CompanyPersonalDocumentFile;
use App\Models\User;
use App\Services\UploadedFileCleaner;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Company Personal Documents — internal, admin-side only. Never exposed to
 * end users (there is no user-facing endpoint here by design).
 *
 * A document is a SET: one record (title / remark / visibility) holding many
 * files, e.g. a "GST Bills" set with ten PDFs. Each file keeps the original
 * upload name so downloads are named the way the uploader expects rather than
 * "{uuid}_{name}".
 *
 * Access: `subadmin.permission:personal_documents` — super-admin always passes,
 * a sub-admin needs the explicit flag.
 *
 * Visibility:
 *   • the super-admin sees every document and owns the visibility flag;
 *   • a sub-admin sees a document only if it is marked visible OR they
 *     uploaded it themselves (so their own submissions never disappear);
 *   • sub-admin uploads default to hidden; a super-admin's upload is visible
 *     at once; a sub-admin may EDIT only their own, still-hidden rows.
 *
 * Deleting is super-admin only — a sub-admin cannot delete even their own
 * uploads, and the UI hides the button because `can_delete` says so.
 */
class CompanyPersonalDocumentController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['id', 'title', 'is_sub_admin_visible', 'created_at'];

    private function isSuperAdmin(): bool
    {
        return (int) (Auth::user()->role ?? -1) === User::ROLE_SUPER_ADMIN;
    }

    public function index(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'id'));
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'id';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));
            $search_param = $this->safeJsonDecode($request->query('search_param', '[]'));

            $query = CompanyPersonalDocument::with([
                'creator:id,username,first_name,last_name,role',
                'files',
            ])->where('is_deleted', 0);

            // A sub-admin only gets documents made visible to them, plus their
            // own uploads. This is what makes the "Sub Admin Visible" flag mean
            // something — without it the label would be cosmetic.
            if (!$this->isSuperAdmin()) {
                $authId = Auth::id();
                $query->where(function ($q) use ($authId) {
                    $q->where('is_sub_admin_visible', 1)
                        ->orWhere('created_by', $authId);
                });
            }

            if ($search_term !== '') {
                $query->where('title', 'LIKE', '%' . $search_term . '%');
            }
            if (isset($search_param['is_sub_admin_visible']) && $search_param['is_sub_admin_visible'] !== '') {
                $query->where('is_sub_admin_visible', (int) $search_param['is_sub_admin_visible']);
            }

            $total_records = (clone $query)->count();

            $items = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)->take($page_size);
                })
                ->get()
                ->map(function ($row) {
                    return $this->present($row);
                });

            return response()->json([
                'success'  => true,
                'message'  => 'Success',
                'data'     => $items,
                // Lets the UI show the show/hide control only to a super-admin.
                'meta'     => [
                    'is_super_admin' => $this->isSuperAdmin(),
                ],
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = $this->validatePayload($request);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $authId = Auth::id();
            $isSuper = $this->isSuperAdmin();

            DB::beginTransaction();

            $doc = new CompanyPersonalDocument();
            $doc->title = $request->title;
            $doc->remark = $request->remark;
            // Legacy single-file columns: keep the first file mirrored here so
            // anything still reading them keeps working.
            $files = $this->normalizeFiles($request);
            $doc->file_path = $files[0]['file_path'] ?? null;
            $doc->file_type = CompanyPersonalDocument::detectFileType($doc->file_path);
            // A sub-admin's upload starts hidden from other sub-admins until
            // the super-admin makes it visible; a super-admin's own upload is
            // visible at once. A sub-admin cannot set this themselves.
            $doc->is_sub_admin_visible = $isSuper ? 1 : 0;
            if ($isSuper) {
                $doc->status_changed_by = $authId;
                $doc->status_changed_at = now();
            }
            $doc->created_by = $authId;
            $doc->updated_by = $authId;
            $doc->save();

            $this->syncFiles($doc, $files);

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('CompanyPersonalDocument store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function show($id)
    {
        $doc = CompanyPersonalDocument::with([
            'creator:id,username,first_name,last_name,role',
            'files',
        ])->where('id', $id)->where('is_deleted', 0)->first();

        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }
        // A sub-admin must not reach a hidden document that isn't theirs just
        // by knowing its id — the listing hides it, so this closes the gap.
        if (!$this->canView($doc)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this document.',
                'code'    => 'forbidden',
            ], 403);
        }

        return response()->json(['success' => true, 'data' => $this->present($doc)], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $doc = CompanyPersonalDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            if (!$this->canModify($doc)) {
                return response()->json([
                    'message' => 'You can only edit your own documents while they are still hidden.',
                    'status'  => 403,
                ], 403);
            }

            $validator = $this->validatePayload($request);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $files = $this->normalizeFiles($request);
            $doc->title = $request->title;
            $doc->remark = $request->remark;
            $doc->file_path = $files[0]['file_path'] ?? null;
            $doc->file_type = CompanyPersonalDocument::detectFileType($doc->file_path);
            $doc->updated_by = Auth::id();
            $doc->save();

            $this->syncFiles($doc, $files);

            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('CompanyPersonalDocument update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Delete a document set. SUPER-ADMIN ONLY — a sub-admin cannot delete even
     * a document they uploaded themselves.
     */
    public function destroy($id)
    {
        try {
            if (!$this->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only the main admin can delete a document.',
                    'status'  => 403,
                ], 403);
            }

            $doc = CompanyPersonalDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $removedFiles = CompanyPersonalDocumentFile::where('document_id', $doc->id)
                ->pluck('file_path')
                ->all();
            $removedFiles[] = $doc->file_path; // legacy single-file column

            DB::beginTransaction();
            $doc->is_deleted = 1;
            $doc->updated_by = Auth::id();
            $doc->save();
            CompanyPersonalDocumentFile::where('document_id', $doc->id)
                ->update(['is_deleted' => 1]);
            DB::commit();

            // Every row is soft-deleted now, so none of these files are still
            // referenced. Outside the transaction on purpose: a failed unlink
            // must not roll back a delete the admin already saw succeed.
            app(UploadedFileCleaner::class)->forgetMany(array_unique(array_filter($removedFiles)));

            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('CompanyPersonalDocument destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Stream a file INLINE for previewing in the browser.
     *
     * The UI uses this instead of the public storage URL: internal documents
     * must not be fetchable by anyone holding a link. Same access rules as
     * download, so a sub-admin can never preview a document they cannot see.
     */
    public function viewFile($id, $fileId)
    {
        try {
            $doc = CompanyPersonalDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc || !$this->canView($doc)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            $file = CompanyPersonalDocumentFile::where('id', $fileId)
                ->where('document_id', $doc->id)
                ->where('is_deleted', 0)
                ->first();
            if (!$file) {
                return response()->json(['success' => false, 'message' => 'File not found'], 404);
            }

            $path = $file->absolutePath();
            if ($path === null) {
                return response()->json(['success' => false, 'message' => 'File is missing on the server'], 404);
            }

            return response()->file($path, [
                'Content-Disposition' => 'inline; filename="' . addslashes($file->downloadName()) . '"',
            ]);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument viewFile failed', [
                'id' => $id, 'file_id' => $fileId, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Stream one file back under its ORIGINAL upload name, not the
     * "{uuid}_{name}" it is stored as.
     */
    public function downloadFile($id, $fileId)
    {
        try {
            $doc = CompanyPersonalDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc || !$this->canView($doc)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            $file = CompanyPersonalDocumentFile::where('id', $fileId)
                ->where('document_id', $doc->id)
                ->where('is_deleted', 0)
                ->first();
            if (!$file) {
                return response()->json(['success' => false, 'message' => 'File not found'], 404);
            }

            $path = $file->absolutePath();
            if ($path === null) {
                return response()->json(['success' => false, 'message' => 'File is missing on the server'], 404);
            }

            return response()->download($path, $file->downloadName());
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument downloadFile failed', [
                'id' => $id, 'file_id' => $fileId, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Zip every file in the set and stream it as one download. Files inside
     * the archive keep their original names; duplicates are suffixed so a set
     * with two "bill.pdf" entries doesn't silently lose one.
     */
    public function downloadAll($id)
    {
        $zipPath = null;

        try {
            $doc = CompanyPersonalDocument::with('files')
                ->where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc || !$this->canView($doc)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
            if ($doc->files->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'This document has no files'], 400);
            }

            $tmpDir = storage_path('app/tmp');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }
            // Sweep archives left behind by aborted downloads before building
            // another. deleteFileAfterSend covers the normal path, but a
            // cancelled or dropped download can strand the temp file, and
            // these are the size of a whole document set.
            $this->pruneStaleArchives($tmpDir);
            $zipPath = $tmpDir . '/cpd_' . $doc->id . '_' . Str::random(8) . '.zip';

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return response()->json(['success' => false, 'message' => 'Could not build the archive'], 500);
            }

            $used = [];
            $added = 0;
            foreach ($doc->files as $file) {
                $path = $file->absolutePath();
                if ($path === null) {
                    continue; // skip a file missing from disk rather than fail the lot
                }

                $entry = $this->uniqueEntryName($file->downloadName(), $used);
                $zip->addFile($path, $entry);
                $added++;
            }
            $zip->close();

            if ($added === 0) {
                @unlink($zipPath);
                return response()->json([
                    'success' => false,
                    'message' => 'None of the files could be found on the server',
                ], 404);
            }

            $zipName = (Str::slug($doc->title) ?: 'documents') . '.zip';

            return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
            Log::error('CompanyPersonalDocument downloadAll failed', [
                'id' => $id, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Show / hide a document from sub-admins — SUPER-ADMIN ONLY.
     * A sub-admin can see the flag but never change it.
     */
    public function statusUpdate(Request $request)
    {
        try {
            if (!$this->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only the main admin can change a document status.',
                    'status'  => 403,
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'id'                   => 'required|integer',
                'is_sub_admin_visible' => 'required|boolean',
                'remark'               => 'nullable|string|max:500',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $doc = CompanyPersonalDocument::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $doc->is_sub_admin_visible = (int) $request->is_sub_admin_visible ? 1 : 0;
            if ($request->filled('remark')) {
                $doc->remark = $request->remark;
            }
            $doc->status_changed_by = Auth::id();
            $doc->status_changed_at = now();
            $doc->updated_by = Auth::id();
            $doc->save();

            return response()->json(['message' => 'Status updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument statusUpdate failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** Mirrors the listing rule: visible to sub-admins, or their own upload. */
    private function canView(CompanyPersonalDocument $doc): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return (int) $doc->is_sub_admin_visible === 1
            || (int) $doc->created_by === (int) Auth::id();
    }

    /** Super-admin: anything. Sub-admin: only their own, still-hidden rows. */
    private function canModify(CompanyPersonalDocument $doc): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return (int) $doc->created_by === (int) Auth::id()
            && (int) $doc->is_sub_admin_visible === 0;
    }

    /** Deleting is reserved for the super-admin, no exceptions. */
    private function canDelete(CompanyPersonalDocument $doc): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * Accept the incoming file set. Supports the new `files[]` payload and
     * falls back to the old single `file_path` so an older client (or a
     * cached bundle mid-deploy) keeps working.
     *
     * @return array<int, array{file_path:string, original_name:string}>
     */
    private function normalizeFiles(Request $request): array
    {
        $raw = $request->input('files');

        if (!is_array($raw) || $raw === []) {
            $legacy = (string) $request->input('file_path', '');
            if ($legacy === '') {
                return [];
            }
            $raw = [['file_path' => $legacy]];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $item = ['file_path' => $item];
            }
            if (!is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $name = trim((string) ($item['original_name'] ?? ''));
            if ($name === '') {
                $name = CompanyPersonalDocumentFile::stripUploadPrefix($path);
            }

            $out[] = [
                'file_path'     => $path,
                // basename() strips any path the client may have sent, so a
                // crafted "../../x" can never steer the download name.
                'original_name' => basename(str_replace('\\', '/', $name)),
            ];
        }

        return $out;
    }

    /**
     * Move a stored file out of the web-servable upload directory into
     * private storage.
     *
     * Best-effort by design: if the move fails (permissions, missing file)
     * the document still works, because absolutePath() falls back to the old
     * public location. A privatisation problem degrades rather than breaks.
     */
    private function privatise(string $storedName): void
    {
        try {
            $name = basename(str_replace('\\', '/', $storedName));
            if ($name === '' || $name === '.' || $name === '..') {
                return;
            }

            $targetDir = storage_path(CompanyPersonalDocumentFile::PRIVATE_DIR);
            $target = $targetDir . '/' . $name;
            if (is_file($target)) {
                return; // already private
            }

            $source = storage_path('app/public/uploads/final/' . $name);
            if (!is_file($source)) {
                return; // nothing to move
            }

            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                Log::warning('Could not create private document directory', ['dir' => $targetDir]);
                return;
            }

            if (!@rename($source, $target)) {
                Log::warning('Personal document privatise failed', ['file' => $name]);
            }
        } catch (\Throwable $e) {
            Log::warning('Personal document privatise errored', [
                'file' => $storedName, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Replace the set's files with exactly what was submitted. */
    private function syncFiles(CompanyPersonalDocument $doc, array $files): void
    {
        // Whatever the edit drops must come off disk, or every edit of a set
        // leaves its removed files behind forever.
        $previous = CompanyPersonalDocumentFile::where('document_id', $doc->id)
            ->pluck('file_path')
            ->all();
        $keeping = array_column($files, 'file_path');
        $dropped = array_diff($previous, $keeping);

        CompanyPersonalDocumentFile::where('document_id', $doc->id)->delete();

        $order = 0;
        foreach ($files as $file) {
            CompanyPersonalDocumentFile::create([
                'document_id'   => $doc->id,
                'file_path'     => $file['file_path'],
                'original_name' => $file['original_name'],
                'file_type'     => CompanyPersonalDocument::detectFileType($file['file_path']),
                'sort_order'    => $order++,
                'is_deleted'    => 0,
            ]);

            // Get it out of the publicly-servable upload directory.
            $this->privatise($file['file_path']);
        }

        // The old rows are gone, so anything dropped is now unreferenced.
        if ($dropped) {
            app(UploadedFileCleaner::class)->forgetMany($dropped);
        }
    }

    /**
     * Delete zips in the temp directory older than an hour. Anything that old
     * is from a download that never completed — a live one is written and
     * streamed within seconds.
     */
    private function pruneStaleArchives(string $dir): void
    {
        try {
            $cutoff = time() - 3600;
            foreach (glob($dir . '/cpd_*.zip') ?: [] as $path) {
                if (is_file($path) && filemtime($path) < $cutoff) {
                    @unlink($path);
                }
            }
        } catch (\Throwable $e) {
            // Housekeeping must never block a download.
            Log::warning('Stale archive prune failed', ['error' => $e->getMessage()]);
        }
    }

    /** Keep archive entry names unique: bill.pdf, bill (1).pdf, … */
    private function uniqueEntryName(string $name, array &$used): string
    {
        $name = $name !== '' ? $name : 'file';
        if (!isset($used[strtolower($name)])) {
            $used[strtolower($name)] = true;
            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $i = 1;
        do {
            $candidate = $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
            $i++;
        } while (isset($used[strtolower($candidate)]));

        $used[strtolower($candidate)] = true;

        return $candidate;
    }

    private function validatePayload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'                 => 'required|string|max:190',
            'files'                 => 'required|array|min:1',
            'files.*.file_path'     => 'required|string',
            'files.*.original_name' => 'nullable|string|max:255',
            'remark'                => 'nullable|string|max:500',
        ], [
            'title.required' => 'Title is required',
            'files.required' => 'Please upload at least one document',
            'files.min'      => 'Please upload at least one document',
        ]);

        $validator->after(function ($v) use ($request) {
            $maxBytes = CompanyPersonalDocument::MAX_FILE_BYTES;
            $maxLabel = round($maxBytes / 1073741824, 1) . ' GB';

            foreach ($this->normalizeFiles($request) as $file) {
                if (!CompanyPersonalDocument::isAllowedFile($file['file_path'])) {
                    $v->errors()->add(
                        'files',
                        $file['original_name'] . ' cannot be uploaded: that file type is not permitted'
                    );
                    continue;
                }

                // The file is already on disk by now (the uploader wrote it),
                // so measure it rather than trusting anything the client sent.
                $path = CompanyPersonalDocumentFile::resolveStoredPath($file['file_path']);
                if ($path !== null && filesize($path) > $maxBytes) {
                    $v->errors()->add(
                        'files',
                        $file['original_name'] . ' is larger than ' . $maxLabel
                    );
                }
            }
        });

        return $validator;
    }

    private function present(CompanyPersonalDocument $row): array
    {
        $creator = $row->creator;
        $isSuper = $this->isSuperAdmin();
        $files = $row->relationLoaded('files') ? $row->files : $row->files()->get();

        $data = [
            'id'                   => $row->id,
            'title'                => $row->title,
            // Legacy single-file fields, kept so nothing depending on them breaks.
            'file_path'            => $row->file_path,
            'file_type'            => (int) $row->file_type,
            'type_label'           => $row->typeLabel(),
            'remark'               => $row->remark,
            'created_by'           => $row->created_by,
            'added_by'             => $creator->username ?? 'N/A',
            'added_by_role'        => $creator
                ? ((int) $creator->role === User::ROLE_SUPER_ADMIN ? 'Admin' : 'Sub Admin')
                : '-',
            // Drives whether the row shows edit / delete for the current actor.
            'can_modify'           => $this->canModify($row),
            'can_delete'           => $this->canDelete($row),
            'file_count'           => $files->count(),
            'files'                => $files->map(function ($file) {
                return [
                    'id'            => $file->id,
                    'file_path'     => $file->file_path,
                    'original_name' => $file->downloadName(),
                    'file_type'     => (int) $file->file_type,
                    'type_label'    => $file->typeLabel(),
                ];
            })->values(),
            'created_at_formatted' => $row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-',
            'status_changed_at'    => $row->status_changed_at
                ? $row->status_changed_at->format('d-m-Y h:i A')
                : null,
        ];

        // Visibility is a super-admin concern only — a sub-admin never sees
        // that the flag exists, so it is omitted from their payload entirely.
        if ($isSuper) {
            $data['is_sub_admin_visible'] = (int) $row->is_sub_admin_visible;
            $data['visibility_label'] = $row->visibilityLabel();
        }

        return $data;
    }
}
