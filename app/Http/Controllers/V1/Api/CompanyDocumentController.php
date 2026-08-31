<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Services\UploadedFileCleaner;
use App\Models\CompanyDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Company Documents.
 *
 * Admin: full CRUD + show/hide toggle. Files are uploaded through the existing
 * chunked /upload endpoint (which leaves non-video files untouched), and only
 * the returned filename is stored here.
 *
 * User: read-only list of active documents for the "Company Docs" screen.
 */
class CompanyDocumentController extends Controller
{
    protected $messages;

    public function __construct()
    {
        $this->messages = [
            'title.required'     => 'Title is required',
            'file_path.required' => 'Please upload an image or PDF',
        ];
    }

    /** Admin listing — paginated, searchable on title. */
    public function index(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'id'));
            if (!in_array($sort_column, ['id', 'title', 'created_at'], true)) {
                $sort_column = 'id';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            $query = CompanyDocument::where('is_deleted', 0);
            if ($search_term !== '') {
                $query->where('title', 'LIKE', '%' . $search_term . '%');
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
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title'     => 'required|string|max:190',
                'file_path' => 'required|string',
                'is_active' => 'nullable|boolean',
            ], $this->messages);

            $validator->after(function ($v) use ($request) {
                if ($request->filled('file_path') && !CompanyDocument::isAllowedFile($request->file_path)) {
                    $v->errors()->add('file_path', 'Only image files or PDFs are allowed');
                }
            });

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $authId = Auth::id();
            $doc = new CompanyDocument();
            $doc->title = $request->title;
            $doc->file_path = $request->file_path;
            $doc->file_type = CompanyDocument::detectFileType($request->file_path);
            $doc->is_active = $request->has('is_active')
                ? ((int) $request->input('is_active') ? 1 : 0)
                : 1;
            $doc->created_by = $authId;
            $doc->updated_by = $authId;
            $doc->save();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function show($id)
    {
        $doc = CompanyDocument::where('id', $id)->where('is_deleted', 0)->first();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }
        return response()->json(['success' => true, 'data' => $this->present($doc)], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $doc = CompanyDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $validator = Validator::make($request->all(), [
                'title'     => 'required|string|max:190',
                'file_path' => 'required|string',
                'is_active' => 'nullable|boolean',
            ], $this->messages);

            $validator->after(function ($v) use ($request) {
                if ($request->filled('file_path') && !CompanyDocument::isAllowedFile($request->file_path)) {
                    $v->errors()->add('file_path', 'Only image files or PDFs are allowed');
                }
            });

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $doc->title = $request->title;
            $previousFile = $doc->file_path;
            $doc->file_path = $request->file_path;
            $doc->file_type = CompanyDocument::detectFileType($request->file_path);
            if ($request->has('is_active')) {
                $doc->is_active = (int) $request->input('is_active') ? 1 : 0;
            }
            $doc->updated_by = Auth::id();
            $doc->save();

            // Drop the previous upload if this edit replaced it.
            app(UploadedFileCleaner::class)->replaced($previousFile, $doc->file_path);

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $doc = CompanyDocument::where('id', $id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $removedFile = $doc->file_path;
            $doc->is_deleted = 1;
            $doc->updated_by = Auth::id();
            $doc->save();

            // The row is soft-deleted now, so nothing live references the
            // file and it can come off disk.
            app(UploadedFileCleaner::class)->forget($removedFile);

            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /** Show / hide toggle. */
    public function statusUpdate(Request $request)
    {
        try {
            $doc = CompanyDocument::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$doc) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : 1;
            $doc->is_active = (int) $isActiveInput ? 1 : 0;
            $doc->updated_by = Auth::id();
            $doc->save();

            return response()->json(['message' => 'Status updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument statusUpdate failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * User-facing list: active documents only, newest first. No pagination —
     * the PWA shows them all on one screen.
     */
    public function userList()
    {
        try {
            $items = CompanyDocument::where('is_deleted', 0)
                ->where('is_active', 1)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($row) {
                    return [
                        'id'         => $row->id,
                        'title'      => $row->title,
                        'file_path'  => $row->file_path,
                        'file_type'  => (int) $row->file_type,
                        'type_label' => $row->typeLabel(),
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $items,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CompanyDocument userList failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    private function present(CompanyDocument $row): array
    {
        return [
            'id'                   => $row->id,
            'title'                => $row->title,
            'file_path'            => $row->file_path,
            'file_type'            => (int) $row->file_type,
            'type_label'           => $row->typeLabel(),
            'is_active'            => (int) $row->is_active,
            'created_at_formatted' => $row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-',
        ];
    }
}
