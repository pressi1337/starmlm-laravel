<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ReferralScratchLevel;
use App\Models\ReferralScratchLevelAmount;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScratchSetupController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    protected $messages;
    public function __construct()
    {
        $this->messages = [
            "promotor_level.required" => "Promotor Level Required",
            "amount.required" => "Amount Required",
            "msg.required" => "Message Required",

        ];
    }
    protected array $sortable = ['created_at', 'id', 'promotor_level', 'level'];
    protected array $filterable = ['id', 'promotor_level', 'level', 'is_active'];

    /**
     * Parse a config key into [promotor_level, referral level].
     * Key is "{promotor_level}_{level}" (level = referral depth 1-7); a bare
     * "{promotor_level}" falls back to level 1 (backward compatible).
     */
    private function parseScratchKey($id): array
    {
        $parts = explode('_', (string) $id);
        $promotorLevel = (int) ($parts[0] ?? 0);
        $level = isset($parts[1]) ? (int) $parts[1] : 1;
        return [$promotorLevel, $level < 1 ? 1 : $level];
    }

    /**
     * Validation rules for the pool of amounts a combination pays out.
     *
     * `amounts` is optional: a request that omits it keeps the legacy single
     * `amount` behaviour untouched, which is what old clients still send.
     */
    private function amountPoolRules(): array
    {
        return [
            'amounts' => 'nullable|array|max:50',
            'amounts.*.id' => 'nullable|integer',
            'amounts.*.amount' => 'required|numeric|min:0',
            'amounts.*.msg' => 'nullable|string|max:255',
        ];
    }

    /**
     * Normalise a save request into the list of pool rows it means.
     *
     * The pool is the single source of truth for payouts, so EVERY save writes
     * one — including a legacy request that carries only a single `amount`,
     * which is stored as a one-entry pool. That keeps it impossible to create
     * a pair that has no pool and silently falls back to the flat column.
     *
     * `amounts: []` (or a single amount of 0) means "this pair pays nothing"
     * and correctly resolves to an empty pool.
     */
    private function resolveAmountPool(Request $request): array
    {
        $pool = $request->input('amounts');
        if (is_array($pool)) {
            return $pool;
        }

        $single = (float) $request->input('amount', 0);
        if ($single <= 0) {
            return [];
        }

        return [['amount' => $single, 'msg' => $request->input('msg')]];
    }

    /**
     * Replace a combination's pool of amounts with the list the admin just
     * saved.
     *
     * Rows carrying an `id` are updated in place, new rows are inserted, and
     * rows that are no longer in the list are soft-deleted (house convention —
     * nothing is physically removed). Returns the amounts that are live after
     * the sync, in order.
     *
     * Entries whose amount is <= 0 are dropped: a zero would sit in the pool
     * and hand out empty scratch cards.
     */
    private function syncAmountPool(ReferralScratchLevel $level, array $rows, $authUserId): array
    {
        $keptIds = [];
        $live = [];
        $order = 0;

        foreach ($rows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $msg = isset($row['msg']) && $row['msg'] !== '' ? (string) $row['msg'] : null;
            $existing = null;
            if (!empty($row['id'])) {
                $existing = ReferralScratchLevelAmount::where('id', (int) $row['id'])
                    ->where('referral_scratch_level_id', $level->id)
                    ->first();
            }

            if (!$existing) {
                $existing = new ReferralScratchLevelAmount();
                $existing->referral_scratch_level_id = $level->id;
                $existing->created_by = $authUserId;
            }

            $existing->amount = $amount;
            $existing->msg = $msg;
            $existing->order_no = $order++;
            $existing->is_active = 1;
            $existing->is_deleted = 0;
            $existing->updated_by = $authUserId;
            $existing->save();

            $keptIds[] = $existing->id;
            $live[] = $existing;
        }

        // Anything the admin removed from the list.
        ReferralScratchLevelAmount::where('referral_scratch_level_id', $level->id)
            ->where('is_deleted', 0)
            ->when(!empty($keptIds), function ($q) use ($keptIds) {
                return $q->whereNotIn('id', $keptIds);
            })
            ->update(['is_deleted' => 1, 'updated_by' => $authUserId]);

        return $live;
    }

    public function index(Request $request)
    {
        try {
            // Read common query params
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination style A: legacy flags
            $is_pagination = (int) $request->query('is_pagination', 0) === 1;
            $row_per_page = (int) $request->query('limit', 10);
            $current_page_number = max(1, (int) $request->query('current_page_num', 1));

            // Pagination style B: standardized
            $page_size = max(0, (int) $request->query('page_size', $row_per_page));
            $page_number = max(1, (int) $request->query('page_number', $current_page_number));

            $search_term = trim((string) $request->query('search', ''));
            $search_param_raw = $request->query('search_param', '{}');
            $search_param = [];
            try {
                $decoded = json_decode($search_param_raw, true);
                if (is_array($decoded)) {
                    $search_param = $decoded;
                }
            } catch (\Throwable $e) {
                $search_param = [];
            }

            $query = ReferralScratchLevel::query()
                ->where(['is_active' => 1, 'is_deleted' => 0])
                ->with(['amounts' => function ($q) {
                    $q->where('is_active', 1)->where('is_deleted', 0);
                }]);

            // Whitelisted filters
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, $this->filterable, true)) {
                    $query->where($key, $value);
                }
            }

            // Search term across fields
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('promotor_level', 'LIKE', '%' . $search_term . '%');
                });
            }

            $total_records = $query->count();

            // Apply sorting and pagination
            $collection = $query->orderBy($sort_column, $sort_direction)
                ->when(($is_pagination && $row_per_page != -1) || $page_size > 0, function ($q) use ($is_pagination, $row_per_page, $current_page_number, $page_size, $page_number) {
                    $limit = $is_pagination ? $row_per_page : $page_size;
                    $page = $is_pagination ? $current_page_number : $page_number;
                    return $q->skip(($page - 1) * max(1, $limit))
                        ->take($limit == -1 ? $q->count() : $limit);
                })
                ->get()
                ->map(function ($item) {
                    $item->created_at_formatted = $item->created_at ? $item->created_at->format('d-m-Y h:i A') : '-';
                    $item->updated_at_formatted = $item->updated_at ? $item->updated_at->format('d-m-Y h:i A') : '-';
                    return $item;
                });

            // Legacy response when is_pagination == 1
            if ($is_pagination) {
                $limit = max(1, (int) $row_per_page);
                $total_pages = (int) ceil($total_records / $limit);
                return response()->json([
                    'referral_scratch_levels' => $collection,
                    'count' => $total_records,
                    'next' => $total_pages > $current_page_number ? $current_page_number + 1 : null,
                ], 200);
            }

            // Standardized response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $collection,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('ScratchSetup index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            $auth_user_id = Auth::id();

            $pool = $request->input('amounts');
            // Present at all (even as an empty list) means the client is
            // stating the pool outright, so the mirrored single amount is
            // derived rather than supplied.
            $poolProvided = is_array($pool);

            $validator = Validator::make($request->all(), array_merge([
                'promotor_level' => 'required|integer',
                'level' => 'nullable|integer',
                'is_active' => 'nullable|boolean',
                'amount' => ($poolProvided ? 'nullable' : 'required') . '|numeric|min:0',
                'msg' => 'nullable|string|max:255',
            ], $this->amountPoolRules()));

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $w = new ReferralScratchLevel();
            $w->promotor_level = $request->promotor_level;
            $w->level = (int) ($request->level ?? 1);
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->amount = (float) $request->amount;
            $w->msg = $request->msg;
            $w->created_by = $auth_user_id;
            $w->updated_by = $auth_user_id;
            $w->save();

            // Always write the pool, even for a single-amount request.
            $live = $this->syncAmountPool($w, $this->resolveAmountPool($request), $auth_user_id);
            // Mirror the first pool entry onto the parent so anything that
            // still reads the single column sees a real configured value.
            $w->amount = !empty($live) ? (float) $live[0]->amount : 0;
            $w->msg = !empty($live) ? $live[0]->msg : $w->msg;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Referral Scratch Level created successfully', 'status' => 200], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ScratchSetup store failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            [$promotorLevel, $level] = $this->parseScratchKey($id);
            $item = ReferralScratchLevel::with(['amounts' => function ($q) {
                    $q->where('is_active', 1)->where('is_deleted', 0);
                }])
                ->where('promotor_level', $promotorLevel)
                ->where('level', $level)
                ->where('is_deleted', 0)
                ->first();

            if (!$item) {
                return response()->json([
                'success' => true,
                'data' => null,
            ], 200);
            }

            $item->created_at_formatted = $item->created_at ? $item->created_at->format('d-m-Y h:i A') : '-';
            $item->updated_at_formatted = $item->updated_at ? $item->updated_at->format('d-m-Y h:i A') : '-';

            return response()->json([
                'success' => true,
                'data' => $item,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('ScratchSetup show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        try {
            [$promotorLevel, $level] = $this->parseScratchKey($id);
            $item = ReferralScratchLevel::with(['amounts' => function ($q) {
                    $q->where('is_active', 1)->where('is_deleted', 0);
                }])
                ->where('promotor_level', $promotorLevel)
                ->where('level', $level)
                ->where('is_deleted', 0)
                ->first();

            if (!$item) {
                 return response()->json([
                'success' => true,
                'data' => null,
            ], 200);
            }

            $item->created_at_formatted = $item->created_at ? $item->created_at->format('d-m-Y h:i A') : '-';
            $item->updated_at_formatted = $item->updated_at ? $item->updated_at->format('d-m-Y h:i A') : '-';

            return response()->json([
                'success' => true,
                'data' => $item,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('ScratchSetup edit failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            $auth_user_id = Auth::id();

            $pool = $request->input('amounts');
            // Present at all (even as an empty list) means the client is
            // stating the pool outright, so the mirrored single amount is
            // derived rather than supplied.
            $poolProvided = is_array($pool);

            $validator = Validator::make($request->all(), array_merge([
                'is_active' => 'nullable|boolean',
                'amount' => ($poolProvided ? 'nullable' : 'required') . '|numeric|min:0',
                'msg' => 'nullable|string|max:255',
            ], $this->amountPoolRules()));

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            [$promotorLevel, $level] = $this->parseScratchKey($id);
            $w = ReferralScratchLevel::where('promotor_level', $promotorLevel)->where('level', $level)->where('is_deleted', 0)->first();
            if (!$w) {
                $w = new ReferralScratchLevel();
                $w->promotor_level = $promotorLevel;
                $w->level = $level;
                $w->created_by = $auth_user_id;
            }
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->amount = (float) $request->amount;
            $w->msg = $request->msg;
            $w->updated_by = $auth_user_id;
            $w->save();

            // Always write the pool, even for a single-amount request.
            $live = $this->syncAmountPool($w, $this->resolveAmountPool($request), $auth_user_id);
            // Mirror the first pool entry onto the parent so anything that
            // still reads the single column sees a real configured value.
            $w->amount = !empty($live) ? (float) $live[0]->amount : 0;
            $w->msg = !empty($live) ? $live[0]->msg : $w->msg;
            $w->save();

            DB::commit();

            return response()->json(['message' => 'Referral Scratch Level updated successfully', 'status' => 200], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ScratchSetup update failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            [$promotorLevel, $level] = $this->parseScratchKey($id);
            $u = ReferralScratchLevel::where('promotor_level', $promotorLevel)->where('level', $level)->first();
            if (!$u) {
                DB::rollBack();
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $u->is_deleted = 1;
            $u->updated_by = Auth::id();
            $u->save();

            ReferralScratchLevelAmount::where('referral_scratch_level_id', $u->id)->update(['is_deleted' => 1]);
            DB::commit();
            return response()->json(['message' => 'Deleted successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ScratchSetup destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }


    public function StatusUpdate(Request $request)
    {
        try {
            $auth_user_id = Auth::id();
            [$promotorLevel, $level] = $this->parseScratchKey($request->id);
            $w = ReferralScratchLevel::where('promotor_level', $promotorLevel)->where('level', $level)->first();
            if (!$w) {
                return response()->json(['message' => 'Data not found', 'status' => 400], 400);
            }
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            return response()->json(['message' => 'Referral Scratch Level updated successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            Log::error('ScratchSetup status update failed', ['id' => $request->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
}
