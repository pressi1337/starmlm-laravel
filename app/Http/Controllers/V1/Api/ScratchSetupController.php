<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ReferralScratchLevel;
use App\Models\ReferralScratchRange;
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
            "start_range.required" => "Start Range Required",
            "end_range.required" => "End Range Required",
            "amount.required" => "Amount Required",
            "msg.required" => "Message Required",

        ];
    }
    protected array $sortable = ['created_at', 'id', 'promotor_level'];
    protected array $filterable = ['id', 'promotor_level', 'is_active'];
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
                ->with(['ranges' => function ($q) {
                    $q->where(['is_active' => 1, 'is_deleted' => 0])
                        ->orderBy('order_no', 'asc');
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

            $validator = Validator::make($request->all(), [
                'promotor_level' => 'required|integer',
                'is_active' => 'nullable|boolean',
                'ranges' => 'required|array|min:1',
                'ranges.*.start_range' => 'required|integer',
                'ranges.*.end_range' => 'required|integer',
                'ranges.*.amount' => 'required|integer',
                'ranges.*.msg' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $w = new ReferralScratchLevel();
            $w->promotor_level = $request->promotor_level;
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->created_by = $auth_user_id;
            $w->updated_by = $auth_user_id;
            $w->save();

            DB::beginTransaction();


            foreach ($request->ranges ?? [] as $index => $rangeData) {
                $range = new ReferralScratchRange();
                $range->referral_scratch_level_id = $w->id;
                $range->start_range = (int) $rangeData['start_range'];
                $range->end_range = (int) $rangeData['end_range'];
                $range->amount = (int) $rangeData['amount'];
                $range->msg = $rangeData['msg'] ?? null;
                $range->order_no = $index + 1;
                $range->created_by =  $auth_user_id;
                $range->updated_by =  $auth_user_id;
                $range->save();
            }

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
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {}

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

            $validator = Validator::make($request->all(), [
                'is_active' => 'nullable|boolean',
                'ranges' => 'required|array|min:1',
                'ranges.*.start_range' => 'required|integer',
                'ranges.*.end_range' => 'required|integer',
                'ranges.*.amount' => 'required|integer',
                'ranges.*.msg' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $w = ReferralScratchLevel::where('id', $id)->where('is_deleted', 0)->first();
            if (!$w) {
                return response()->json(['message' => 'Not found'], 404);
            }

            DB::beginTransaction();

            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->updated_by =  $auth_user_id;
            $w->save();

            ReferralScratchRange::where('referral_scratch_level_id', $id)->update(['is_deleted' => 1]);

            foreach ($request->ranges ?? [] as $index => $rangeData) {
                $range = new ReferralScratchRange();
                $range->referral_scratch_level_id = $w->id;
                $range->start_range = (int) $rangeData['start_range'];
                $range->end_range = (int) $rangeData['end_range'];
                $range->amount = (int) $rangeData['amount'];
                $range->msg = $rangeData['msg'] ?? null;
                $range->order_no = $index + 1;
                $range->is_active = 1;
                $range->is_deleted = 0;
                $range->created_by =  $auth_user_id;
                $range->updated_by =  $auth_user_id;
                $range->save();
            }

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
        // Soft delete the quiz
        $u = ReferralScratchLevel::find($id);
        $u->is_deleted = 1;
        $u->updated_by = Auth::id();
        $u->save();

        ReferralScratchRange::where('referral_scratch_level_id', $id)->update(['is_deleted' => 1]);

        return response()->json(['status' => 200]);
    }


    public function StatusUpdate(Request $request)
    {

        $auth_user_id = Auth::id();
        $w = ReferralScratchLevel::find($request->id);
        // Use provided is_active/active when present; default to 1 when absent
        $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
        $w->is_active = (int) $isActiveInput ? 1 : 0;
        $w->updated_by =  $auth_user_id;
        $w->save();

        return response()->json(['message' => 'Referral Scratch Level updated successfully', 'status' => 200]);
    }
}
