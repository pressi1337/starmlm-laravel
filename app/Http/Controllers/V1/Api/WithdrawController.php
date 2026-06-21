<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Traits\HandlesJson;
use App\Models\EarningHistory;
use App\Models\User;
use App\Models\UserBankDetail;
use App\Models\WithdrawRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WithdrawRequestExport;
use Carbon\Carbon;
class WithdrawController extends Controller
{
    use HandlesJson;
    /**
     * Display a listing of the resource.
     */

    protected array $sortable = ['created_at', 'amount', 'earning_date', 'earning_type', 'earning_status', 'id', 'description'];
    protected array $filterable = ['amount', 'earning_date', 'earning_type', 'earning_status', 'id', 'description'];
    protected array $filterable1 = ['amount', 'request_at', 'status', 'wallet_type', 'id','reason'];
    protected $messages;
    public function __construct()
    {
        $this->messages = [
            "amount.required" => "Amount Required",
            "wallet_type.required" => "Wallet Type Required",
        ];
    }
    public function index(Request $request)
    {
        if (Auth::user()->role == User::ROLE_ADMIN) {
          try {
            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination parameters
            $page_size = max(0, (int) $request->query('page_size', 10)); // 0 disables pagination
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            // Parse search_param JSON safely
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

            // Start building the query
            $query = WithdrawRequest::query();

            // Apply default filters
            $query->where('is_deleted', 0);

            // Whitelist filterable columns for this listing (kept local — the
            // class-level $filterable belongs to the earnings endpoint).
            $allowedFilters = ['status', 'wallet_type', 'id', 'amount'];

            // Apply search_param filters (whitelisted). Date range and the
            // username pseudo-filter are handled below as special cases.
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if ($key === 'fromdate' || $key === 'todate') {
                    continue;
                }
                if (in_array($key, $allowedFilters, true)) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, $value);
                    }
                }
            }

            // Date range over request_at
            $fromDate = $search_param['fromdate'] ?? null;
            $toDate = $search_param['todate'] ?? null;
            if ($fromDate && $toDate) {
                $query->whereBetween('request_at', [$fromDate, $toDate]);
            } elseif ($fromDate) {
                $query->whereDate('request_at', '>=', $fromDate);
            } elseif ($toDate) {
                $query->whereDate('request_at', '<=', $toDate);
            }

            // Search targets the user's username only.
            if ($search_term !== '') {
                $query->whereHas('user', function ($q) use ($search_term) {
                    $q->where('username', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Get total records for pagination
            $total_records = $query->count();

            // Aggregate cards — share the same scope as the list (date range
            // + username search) but ignore the status filter so each per-
            // status tile stays meaningful even when a status is selected.
            // Built fresh (not cloned-and-stripped) to avoid breaking the
            // builder's internal where/bindings sync.
            $statsBase = function () use ($fromDate, $toDate, $search_term) {
                $q = WithdrawRequest::query()->where('is_deleted', 0);
                if ($fromDate && $toDate) {
                    $q->whereBetween('request_at', [$fromDate, $toDate]);
                } elseif ($fromDate) {
                    $q->whereDate('request_at', '>=', $fromDate);
                } elseif ($toDate) {
                    $q->whereDate('request_at', '<=', $toDate);
                }
                if ($search_term !== '') {
                    $q->whereHas('user', function ($u) use ($search_term) {
                        $u->where('username', 'LIKE', '%' . $search_term . '%');
                    });
                }
                return $q;
            };

            $stats = [
                'total_requests'         => $statsBase()->count(),
                'total_requested_amount' => (float) $statsBase()->sum('amount'),
                'total_withdrawn_amount' => (float) $statsBase()->where('status', WithdrawRequest::STATUS_COMPLETED)->sum('amount'),
                'total_pending_amount'   => (float) $statsBase()->where('status', WithdrawRequest::STATUS_PENDING)->sum('amount'),
            ];

            // Apply sorting and pagination
            $withdraw_histories = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)
                        ->take($page_size);
                })
                ->with('user')
                ->with('bankDetail')
                ->get()
                ->map(function ($withdraw_history) {
                    $withdraw_history->created_at_formatted = $withdraw_history->created_at
                        ? $withdraw_history->created_at->format('d-m-Y h:i A')
                        : '-';
                    $withdraw_history->updated_at_formatted = $withdraw_history->updated_at
                        ? $withdraw_history->updated_at->format('d-m-Y h:i A')
                        : '-';
                    return $withdraw_history;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $withdraw_histories,
                'stats' => $stats,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Withdraw History index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
        }else{
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // validate request
        $validator = Validator::make($request->all(), [
            "amount" => "required",
            "wallet_type" => "required",
        ], $this->messages);
        $auth_user_id = Auth::user()->id;
        $user = User::find($auth_user_id);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Bank details are mandatory before any withdrawal. The frontend blocks
        // this, but enforce it server-side too — a record can be missing OR
        // exist-but-empty (admin "clear bank details" nulls every field), so we
        // check the required fields are actually populated, not just that a row
        // exists. This is the gap that let a request through without a bank.
        $bankDetail = UserBankDetail::where('user_id', $user->id)->first();
        $hasBankDetails = $bankDetail
            && filled($bankDetail->acc_no)
            && filled($bankDetail->acc_name)
            && filled($bankDetail->ifsc_code)
            && filled($bankDetail->bank_name)
            && filled($bankDetail->branch_name);
        if (!$hasBankDetails) {
            return response()->json([
                'success' => false,
                'message' => 'Please add your bank details before requesting a withdrawal.',
            ], 400);
        }

        if ($request->wallet_type == WithdrawRequest::WALLET_TYPE_MAIN) {
            //    each wallet base different validation applicable
            // WALLET_TYPE_MAIN  -->min 100 amount ,30 days once only 
            if ($request->amount < 100) {
                return response()->json(['success' => false, 'message' => 'Minimum amount is 100'], 400);
            }
            $quiz_total_earning = $user->quiz_total_earning;
            $quiz_total_withdraw = $user->quiz_total_withdraw;
            $available = $quiz_total_earning - $quiz_total_withdraw;
            if ($available < $request->amount) {
                return response()->json(['success' => false, 'message' => 'Insufficient balance'], 400);
            }
            $last_withdraw_request = WithdrawRequest::where('user_id', $user->id)
                ->where('wallet_type', WithdrawRequest::WALLET_TYPE_MAIN)
                ->where('status', '!=', WithdrawRequest::STATUS_REJECTED)
                ->where('request_at', '>=', now()->subDays(30))->first();
            if ($last_withdraw_request) {
                $message = "You have already requested for withdraw";
                if ($last_withdraw_request->status == WithdrawRequest::STATUS_PENDING) {
                    $message = "You have already requested for withdraw";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_PROCESSING) {
                    $message = "You have already requested for  processing";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_COMPLETED) {
                    $message = "You have already withdrawn within 30 days try after date 30days from last withdraw Date";
                }
                return response()->json(['success' => false, 'message' => $message], 400);
            }
        }
        if ($request->wallet_type == WithdrawRequest::WALLET_TYPE_SCRATCH) {
            // WALLET_TYPE_SCRATCH-->min 100 amount,weekly once

            if ($request->amount < 100) {
                return response()->json(['success' => false, 'message' => 'Minimum amount is 100'], 400);
            }
            $quiz_total_earning = $user->scratch_total_earning;
            $quiz_total_withdraw = $user->scratch_total_withdraw;
            $available = $quiz_total_earning - $quiz_total_withdraw;
            if ($available < $request->amount) {
                return response()->json(['success' => false, 'message' => 'Insufficient balance'], 400);
            }
            $last_withdraw_request = WithdrawRequest::where('user_id', $user->id)
                ->where('wallet_type', WithdrawRequest::WALLET_TYPE_SCRATCH)
                ->where('status', '!=', WithdrawRequest::STATUS_REJECTED)
                ->where('request_at', '>=', now()->subDays(7))->first();
            if ($last_withdraw_request) {
                $message = "You have already requested for withdraw";
                if ($last_withdraw_request->status == WithdrawRequest::STATUS_PENDING) {
                    $message = "You have already requested for withdraw";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_PROCESSING) {
                    $message = "You have already requested for  processing";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_COMPLETED) {
                    $message = "You have already withdrawn within 7 days try after date 7days from last withdraw Date";
                }
                return response()->json(['success' => false, 'message' => $message], 400);
            }
        }
        if ($request->wallet_type == WithdrawRequest::WALLET_TYPE_GROW) {
            // WALLET_TYPE_GROW-->min 100000 amount,30 days once only

            if ($request->amount < 100000) {
                return response()->json(['success' => false, 'message' => 'Minimum amount is 100000'], 400);
            }
            $quiz_total_earning = $user->saving_total_earning;
            $quiz_total_withdraw = $user->saving_total_withdraw;
            $available = $quiz_total_earning - $quiz_total_withdraw;
            if ($available < $request->amount) {
                return response()->json(['success' => false, 'message' => 'Insufficient balance'], 400);
            }
            $last_withdraw_request = WithdrawRequest::where('user_id', $user->id)
                ->where('wallet_type', WithdrawRequest::WALLET_TYPE_GROW)
                ->where('status', '!=', WithdrawRequest::STATUS_REJECTED)
                ->where('request_at', '>=', now()->subDays(30))->first();
            if ($last_withdraw_request) {
                $message = "You have already requested for withdraw";
                if ($last_withdraw_request->status == WithdrawRequest::STATUS_PENDING) {
                    $message = "You have already requested for withdraw";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_PROCESSING) {
                    $message = "You have already requested for  processing";
                } elseif ($last_withdraw_request->status == WithdrawRequest::STATUS_COMPLETED) {
                    $message = "You have already withdrawn within 30 days try after date 30days from last withdraw Date";
                }
                return response()->json(['success' => false, 'message' => $message], 400);
            }
        }



        try {
            $withdraw = new WithdrawRequest();
            $withdraw->user_id = $user->id;
            $withdraw->amount = $request->amount;
            $withdraw->request_at = now();
            $withdraw->status = WithdrawRequest::STATUS_PENDING;
            $withdraw->wallet_type = $request->wallet_type;
            $withdraw->created_by = $user->id;
            $withdraw->updated_by = $user->id;
            $withdraw->save();
            if($request->wallet_type == WithdrawRequest::WALLET_TYPE_MAIN){
                $user->quiz_total_withdraw += $request->amount;
            }elseif($request->wallet_type == WithdrawRequest::WALLET_TYPE_SCRATCH){
                $user->scratch_total_withdraw += $request->amount;
            }elseif($request->wallet_type == WithdrawRequest::WALLET_TYPE_GROW){
                $user->saving_total_withdraw += $request->amount;
            }
            $user->save();
            
            return response()->json(['success' => true, 'message' => 'Withdraw request created successfully'], 200);
        } catch (\Throwable $th) {
            Log::error('Withdraw request failed', ['error' => $th->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function earningHistory(Request $request)
    {
        try {
            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination parameters
            $page_size = max(0, (int) $request->query('page_size', 10)); // 0 disables pagination
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            // Parse search_param JSON safely
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

            // Start building the query
            $query = EarningHistory::query();

            // Apply default filters
            $query->where('is_deleted', 0)->where('user_id', Auth::id());

            // Apply search_param filters (whitelisted)
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, $this->filterable, true)) {
                    $query->where($key, $value);
                }
            }

            // Apply search filter across common fields
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('amount', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('earning_date', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('earning_type', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('earning_status', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('description', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Get total records for pagination
            $total_records = $query->count();

            // Apply sorting and pagination
            $earning_histories = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)
                        ->take($page_size);
                })
                ->get()
                ->map(function ($earning_history) {
                    $earning_history->created_at_formatted = $earning_history->created_at
                        ? $earning_history->created_at->format('d-m-Y h:i A')
                        : '-';
                    $earning_history->updated_at_formatted = $earning_history->updated_at
                        ? $earning_history->updated_at->format('d-m-Y h:i A')
                        : '-';
                    return $earning_history;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $earning_histories,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Earning History index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
    public function withdrawHistory(Request $request)
    {
        try {
            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination parameters
            $page_size = max(0, (int) $request->query('page_size', 10)); // 0 disables pagination
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            // Parse search_param JSON safely
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

            // Start building the query
            $query = WithdrawRequest::query();

            // Apply default filters
            $query->where('is_deleted', 0)->where('user_id', Auth::id());

            // Apply search_param filters (whitelisted)
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, $this->filterable1, true)) {
                    $query->where($key, $value);
                }
            }

            // Apply search filter across common fields
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('amount', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('request_at', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('status', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('wallet_type', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('reason', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Get total records for pagination
            $total_records = $query->count();

            // Apply sorting and pagination
            $withdraw_histories = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)
                        ->take($page_size);
                })
                ->with('bankDetail')
                ->get()
                ->map(function ($withdraw_history) {
                    $withdraw_history->created_at_formatted = $withdraw_history->created_at
                        ? $withdraw_history->created_at->format('d-m-Y h:i A')
                        : '-';
                    $withdraw_history->updated_at_formatted = $withdraw_history->updated_at
                        ? $withdraw_history->updated_at->format('d-m-Y h:i A')
                        : '-';
                    return $withdraw_history;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $withdraw_histories,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Withdraw History index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
    public function withdrawStatusUpdate(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "id" => "required",
                "status" => "required",
            ], $this->messages);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            $withdraw_request = WithdrawRequest::find($request->id);
            if (!$withdraw_request) {
                return response()->json(['success' => false, 'message' => 'Withdraw request not found'], 422);
            }
            $withdraw_request->status = $request->status;
            $withdraw_request->reason = $request->reason;
            $withdraw_request->save();
            if($request->status == WithdrawRequest::STATUS_REJECTED){
                $user = User::find($withdraw_request->user_id);
                if($withdraw_request->wallet_type == WithdrawRequest::WALLET_TYPE_MAIN){        
                    $user->quiz_total_withdraw -= $withdraw_request->amount;
                }else if($withdraw_request->wallet_type == WithdrawRequest::WALLET_TYPE_SCRATCH){
                    $user->scratch_total_withdraw -= $withdraw_request->amount;
                }else if($withdraw_request->wallet_type == WithdrawRequest::WALLET_TYPE_GROW){
                    $user->saving_total_withdraw -= $withdraw_request->amount;
                }
                $user->save();
            }
            return response()->json(['success' => true, 'message' => 'Withdraw request updated successfully'], 200);
        } catch (\Throwable $e) {
            Log::error('Withdraw Status Update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Export withdraw requests to Excel
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportExcel()
    {


        try {
            // Get all withdraw requests with user and bank details
            $withdrawRequests = WithdrawRequest::where('is_deleted', 0)
                ->where('status', '=', WithdrawRequest::STATUS_PENDING)
                ->with(['user', 'bankDetail'])
                ->orderBy('created_at', 'DESC')
                ->get();

            return Excel::download(
                new WithdrawRequestExport($withdrawRequests),
                'withdraw_requests_' . date('Y-m-d_H-i-s') . '.xlsx'
            );
        } catch (\Exception $e) {
            Log::error('Withdraw export failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to export data'], 500);
        }
    }

    /**
     * Filter-aware Excel export — mirrors index()'s scope (status,
     * fromdate/todate, username search). Distinct from the pending-only
     * exportExcel() above, which is kept for the existing quick-export
     * button.
     */
    public function exportFilteredExcel(Request $request)
    {
        if (Auth::user()->role != User::ROLE_ADMIN) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $search_term = trim((string) $request->query('search', ''));
            $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

            $allowedFilters = ['status', 'wallet_type', 'id', 'amount'];

            $query = WithdrawRequest::query()->where('is_deleted', 0);

            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if ($key === 'fromdate' || $key === 'todate') {
                    continue;
                }
                if (in_array($key, $allowedFilters, true)) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, $value);
                    }
                }
            }

            $fromDate = $search_param['fromdate'] ?? null;
            $toDate = $search_param['todate'] ?? null;
            if ($fromDate && $toDate) {
                $query->whereBetween('request_at', [$fromDate, $toDate]);
            } elseif ($fromDate) {
                $query->whereDate('request_at', '>=', $fromDate);
            } elseif ($toDate) {
                $query->whereDate('request_at', '<=', $toDate);
            }

            if ($search_term !== '') {
                $query->whereHas('user', function ($q) use ($search_term) {
                    $q->where('username', 'LIKE', '%' . $search_term . '%');
                });
            }

            $rows = $query->with(['user', 'bankDetail'])
                ->orderBy('created_at', 'DESC')
                ->get();

            return Excel::download(
                new WithdrawRequestExport($rows),
                'withdraw_requests_filtered_' . date('Y-m-d_H-i-s') . '.xlsx'
            );
        } catch (\Throwable $e) {
            Log::error('Withdraw filtered export failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to export data'], 500);
        }
    }
}
