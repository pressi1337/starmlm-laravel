<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyPersonalDocument;
use App\Models\User;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Company Personal Documents — internal, admin-side only. Never exposed to
 * end users (there is no user-facing endpoint here by design).
 *
 * Access: `subadmin.permission:personal_documents` — super-admin always passes,
 * a sub-admin needs the explicit flag.
 *
 * Workflow:
 *   • sub-admin adds  -> saved INACTIVE, and they can only edit or delete
 *     their OWN entry while it is still inactive;
 *   • super-admin adds -> ACTIVE immediately;
 *   • only a super-admin can show / hide (toggle active).
 */
class CompanyPersonalDocumentController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['id', 'title', 'is_active', 'created_at'];

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

            $query = CompanyPersonalDocument::with(['creator:id,username,first_name,last_name,role'])
                ->where('is_deleted', 0);

            if ($search_term !== '') {
                $query->where('title', 'LIKE', '%' . $search_term . '%');
            }
            if (isset($search_param['is_active']) && $search_param['is_active'] !== '') {
                $query->where('is_active', (int) $search_param['is_active']);
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

            $doc = new CompanyPersonalDocument();
            $doc->title = $request->title;
            $doc->file_path = $request->file_path;
            $doc->file_type = CompanyPersonalDocument::detectFileType($request->file_path);
            $doc->remark = $request->remark;
            // A sub-admin's upload starts hidden until the super-admin shows
            // it; a super-admin's own upload is active right away. A sub-admin
            // cannot set this themselves.
            $doc->is_active = $isSuper ? 1 : 0;
            if ($isSuper) {
                $doc->status_changed_by = $authId;
                $doc->status_changed_at = now();
            }
            $doc->created_by = $authId;
            $doc->updated_by = $authId;
            $doc->save();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function show($id)
    {
        $doc = CompanyPersonalDocument::with(['creator:id,username,first_name,last_name,role'])
            ->where('id', $id)->where('is_deleted', 0)->first();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
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
                    'message' => 'You can only edit your own documents while they are inactive.',
                    'status'  => 403,
                ], 403);
            }

            $validator = $this->validatePayload($request);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $doc->title = $request->title;
            $doc->file_path = $request->file_path;
            $doc->file_type = CompanyPersonalDocument::detectFileType($request->file_path);
            $doc->remark = $request->remark;
            $doc->updated_by = Auth::id();
            $doc->save();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $doc = CompanyPersonalDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            if (!$this->canModify($doc)) {
                return response()->json([
                    'message' => 'You can only delete your own documents while they are inactive.',
                    'status'  => 403,
                ], 403);
            }

            $doc->is_deleted = 1;
            $doc->updated_by = Auth::id();
            $doc->save();

            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyPersonalDocument destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Show / hide a document — SUPER-ADMIN ONLY.
     * A sub-admin can see the status but never change it.
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
                'id'        => 'required|integer',
                'is_active' => 'required|boolean',
                'remark'    => 'nullable|string|max:500',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $doc = CompanyPersonalDocument::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $doc->is_active = (int) $request->is_active ? 1 : 0;
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

    /** Super-admin: anything. Sub-admin: only their own, still-inactive rows. */
    private function canModify(CompanyPersonalDocument $doc): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return (int) $doc->created_by === (int) Auth::id()
            && (int) $doc->is_active === 0;
    }

    private function validatePayload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'     => 'required|string|max:190',
            'file_path' => 'required|string',
            'remark'    => 'nullable|string|max:500',
        ], [
            'title.required'     => 'Title is required',
            'file_path.required' => 'Please upload a document',
        ]);

        $validator->after(function ($v) use ($request) {
            if ($request->filled('file_path')
                && !CompanyPersonalDocument::isAllowedFile($request->file_path)) {
                $v->errors()->add('file_path', 'Only image, PDF or Excel files are allowed');
            }
        });

        return $validator;
    }

    private function present(CompanyPersonalDocument $row): array
    {
        $creator = $row->creator;
        return [
            'id'                   => $row->id,
            'title'                => $row->title,
            'file_path'            => $row->file_path,
            'file_type'            => (int) $row->file_type,
            'type_label'           => $row->typeLabel(),
            'is_active'            => (int) $row->is_active,
            'status_label'         => $row->statusLabel(),
            'remark'               => $row->remark,
            'created_by'           => $row->created_by,
            'added_by'             => $creator->username ?? 'N/A',
            'added_by_role'        => $creator
                ? ((int) $creator->role === User::ROLE_SUPER_ADMIN ? 'Admin' : 'Sub Admin')
                : '-',
            // Drives whether the row shows edit/delete for the current actor.
            'can_modify'           => $this->canModify($row),
            'created_at_formatted' => $row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-',
            'status_changed_at'    => $row->status_changed_at
                ? $row->status_changed_at->format('d-m-Y h:i A')
                : null,
        ];
    }
}
