<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\Suggestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Suggestions — user CRUD + admin read-only listing & mark-as-read.
 *
 * User contract (under userjwt):
 *   • index()   — own suggestions, newest first.
 *   • store()   — new suggestion. Rejected if user already has the
 *                 MAX_UNREAD_PER_USER cap of pending (un-read, non-deleted) rows,
 *                 or if content exceeds MAX_WORDS.
 *   • update()  — edit own suggestion. Only allowed while is_read=0 and is_deleted=0.
 *   • destroy() — soft-delete own suggestion. Only while is_read=0.
 *
 * Admin contract (under jwt + role:0):
 *   • adminIndex()   — every user's suggestions with user info, filterable.
 *   • markRead()     — flip is_read=1, stamp read_at + read_by. Idempotent.
 */
class SuggestionController extends Controller
{
    // -------------------- USER-SIDE --------------------

    /** Word-count validator that mirrors the str_word_count check used below. */
    private function tooManyWords(?string $content): bool
    {
        return $content !== null && str_word_count($content) > Suggestion::MAX_WORDS;
    }

    public function index()
    {
        $userId = Auth::id();
        $items = Suggestion::where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'desc')
            ->get();

        $unreadCount = Suggestion::where('user_id', $userId)
            ->where('is_deleted', 0)
            ->where('is_read', 0)
            ->count();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'unread_count' => $unreadCount,
                'unread_cap'   => Suggestion::MAX_UNREAD_PER_USER,
                'can_add_more' => $unreadCount < Suggestion::MAX_UNREAD_PER_USER,
                'max_words'    => Suggestion::MAX_WORDS,
            ],
        ], 200);
    }

    public function show($id)
    {
        $userId = Auth::id();
        $item = Suggestion::where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $item], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ], [
            'content.required' => 'Please write your suggestion',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if ($this->tooManyWords($request->content)) {
            return response()->json([
                'errors' => ['content' => ['Suggestion must be ' . Suggestion::MAX_WORDS . ' words or fewer']],
            ], 422);
        }

        $userId = Auth::id();
        try {
            DB::beginTransaction();
            // Recheck the cap *inside* the transaction so two concurrent
            // submits from the same account can't both squeeze through.
            $unreadCount = Suggestion::where('user_id', $userId)
                ->where('is_deleted', 0)
                ->where('is_read', 0)
                ->lockForUpdate()
                ->count();
            if ($unreadCount >= Suggestion::MAX_UNREAD_PER_USER) {
                DB::rollBack();
                return response()->json([
                    'message' => 'You already have ' . Suggestion::MAX_UNREAD_PER_USER
                        . ' suggestions pending. Wait for admin to mark one as read before sending more.',
                    'status'  => 400,
                ], 400);
            }

            $row = new Suggestion();
            $row->user_id = $userId;
            $row->content = $request->content;
            $row->is_read = 0;
            $row->save();
            DB::commit();

            return response()->json(['message' => 'Suggestion submitted', 'status' => 200, 'data' => $row], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Suggestion store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $userId = Auth::id();
        $row = Suggestion::where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if (!$row) {
            return response()->json(['message' => 'Not found', 'status' => 404], 404);
        }
        if ((int) $row->is_read === 1) {
            return response()->json([
                'message' => 'This suggestion has been read by admin and cannot be edited.',
                'status'  => 400,
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ], [
            'content.required' => 'Please write your suggestion',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if ($this->tooManyWords($request->content)) {
            return response()->json([
                'errors' => ['content' => ['Suggestion must be ' . Suggestion::MAX_WORDS . ' words or fewer']],
            ], 422);
        }

        try {
            $row->content = $request->content;
            $row->save();
            return response()->json(['message' => 'Updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('Suggestion update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    public function destroy($id)
    {
        $userId = Auth::id();
        $row = Suggestion::where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if (!$row) {
            return response()->json(['message' => 'Not found', 'status' => 404], 404);
        }
        if ((int) $row->is_read === 1) {
            return response()->json([
                'message' => 'This suggestion has been read by admin and cannot be deleted.',
                'status'  => 400,
            ], 400);
        }

        try {
            $row->is_deleted = 1;
            $row->save();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('Suggestion destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    // -------------------- ADMIN-SIDE --------------------

    /** Paginated admin listing across every user. Filter by is_read via search_param. */
    public function adminIndex(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, ['id', 'created_at', 'is_read', 'read_at'], true)) {
                $sort_column = 'created_at';
            }
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            $search_param = [];
            try {
                $decoded = json_decode($request->query('search_param', '{}'), true);
                if (is_array($decoded)) {
                    $search_param = $decoded;
                }
            } catch (\Throwable $e) {
                $search_param = [];
            }

            $query = Suggestion::query()->where('is_deleted', 0);

            if (isset($search_param['is_read']) && $search_param['is_read'] !== '' && $search_param['is_read'] !== null) {
                $query->where('is_read', (int) $search_param['is_read']);
            }
            if ($search_term !== '') {
                $like = '%' . $search_term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('content', 'LIKE', $like)
                        ->orWhereHas('user', function ($u) use ($like) {
                            $u->where('username', 'LIKE', $like)
                                ->orWhere('first_name', 'LIKE', $like)
                                ->orWhere('last_name', 'LIKE', $like)
                                ->orWhere('customer_id', 'LIKE', $like);
                        });
                });
            }

            $total_records = $query->count();

            $items = $query->with(['user:id,username,customer_id,first_name,last_name,mobile'])
                ->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)->take($page_size);
                })
                ->get();

            $stats = [
                'total'      => Suggestion::where('is_deleted', 0)->count(),
                'unread'     => Suggestion::where('is_deleted', 0)->where('is_read', 0)->count(),
                'read'       => Suggestion::where('is_deleted', 0)->where('is_read', 1)->count(),
            ];

            return response()->json([
                'success' => true,
                'data'    => $items,
                'stats'   => $stats,
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Suggestion adminIndex failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /** Flip is_read=1; idempotent if already read. Stamps timestamps + actor. */
    public function markRead(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {
            $row = Suggestion::where('id', $request->id)
                ->where('is_deleted', 0)
                ->first();
            if (!$row) {
                return response()->json(['message' => 'Not found', 'status' => 404], 404);
            }
            if ((int) $row->is_read === 1) {
                return response()->json(['message' => 'Already marked as read', 'status' => 200], 200);
            }
            $row->is_read = 1;
            $row->read_at = now();
            $row->read_by = Auth::id();
            $row->save();
            return response()->json(['message' => 'Marked as read', 'status' => 200], 200);
        } catch (\Throwable $e) {
            Log::error('Suggestion markRead failed', ['id' => $request->id ?? null, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
}
