<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportHelp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Support & Help Q&A admin CRUD + public list.
 *
 * Admin endpoints (super-admin only via `role:0` middleware) follow the same
 * filterable/sortable/paginated contract as the other admin lists.
 *
 * The user-facing list endpoint returns active items ordered by id ASC so the
 * PWA accordion always renders entries in the order the admin added them.
 */
class SupportHelpController extends Controller
{
    protected $messages;

    public function __construct()
    {
        $this->messages = [
            'question.required' => 'Question is required',
            'answer.required'   => 'Answer is required',
        ];
    }

    /** Admin listing — paginated, searchable on question/answer text. */
    public function index(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', 'id');
            $sort_direction = strtoupper($request->query('sort_direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            $query = SupportHelp::query()->where('is_deleted', 0);

            if ($search_term !== '') {
                $like = '%' . $search_term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('question', 'LIKE', $like)
                        ->orWhere('answer', 'LIKE', $like);
                });
            }

            $total_records = $query->count();

            $items = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)->take($page_size);
                })
                ->get()
                ->map(function ($row) {
                    $row->created_at_formatted = $row->created_at
                        ? $row->created_at->format('d-m-Y h:i A')
                        : '-';
                    return $row;
                });

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $items,
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('SupportHelp index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'question'  => 'required|string',
                'answer'    => 'required|string',
                'is_active' => 'nullable|boolean',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();
            $authId = Auth::id();
            $w = new SupportHelp();
            $w->question = $request->question;
            $w->answer = $request->answer;
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : 1;
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->created_by = $authId;
            $w->updated_by = $authId;
            $w->save();
            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SupportHelp store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function show($id)
    {
        $item = SupportHelp::where('id', $id)->where('is_deleted', 0)->first();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }
        return response()->json(['success' => true, 'data' => $item], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $item = SupportHelp::where('id', $id)->where('is_deleted', 0)->first();
            if (!$item) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }

            $validator = Validator::make($request->all(), [
                'question'  => 'required|string',
                'answer'    => 'required|string',
                'is_active' => 'nullable|boolean',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();
            $item->question = $request->question;
            $item->answer = $request->answer;
            if ($request->has('is_active')) {
                $item->is_active = (int) $request->input('is_active') ? 1 : 0;
            }
            $item->updated_by = Auth::id();
            $item->save();
            DB::commit();

            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SupportHelp update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $item = SupportHelp::where('id', $id)->where('is_deleted', 0)->first();
            if (!$item) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            DB::beginTransaction();
            $item->is_deleted = 1;
            $item->updated_by = Auth::id();
            $item->save();
            DB::commit();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SupportHelp destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /** Toggle is_active (admin). */
    public function statusUpdate(Request $request)
    {
        try {
            $item = SupportHelp::where('id', $request->id)->where('is_deleted', 0)->first();
            if (!$item) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : 1;
            $item->is_active = (int) $isActiveInput ? 1 : 0;
            $item->updated_by = Auth::id();
            $item->save();
            return response()->json(['message' => 'Status updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('SupportHelp statusUpdate failed', ['id' => $request->id ?? null, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * User-facing list: every active Q&A in admin-insertion order (id ASC).
     * No pagination — the PWA accordion shows them all on a single screen.
     */
    public function userList()
    {
        try {
            $items = SupportHelp::where('is_deleted', 0)
                ->where('is_active', 1)
                ->orderBy('id', 'asc')
                ->get(['id', 'question', 'answer']);

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data'    => $items,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('SupportHelp userList failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}
