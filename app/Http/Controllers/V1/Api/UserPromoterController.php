<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Traits\HandlesJson;
use App\Models\AdditionalScratchReferral;
use App\Models\EarningHistory;
use App\Models\ReferralScratchLevel;
use App\Models\ReferralScratchRange;
use App\Models\ScratchCard;
use App\Models\User;
use App\Models\UserPromoter;
use App\Exports\PinRequestExport;
use App\Services\LevelIncomePayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class UserPromoterController extends Controller
{
    use HandlesJson;
    /**
     * Display a listing of the resource.
     */
    protected $messages;
    protected array $sortable = ['created_at', 'level', 'status', 'updated_at', 'activated_at', 'pin_generated_at', 'product_delivery_status'];
    protected array $filterable = ['level', 'status', 'user_id', 'fromdate', 'todate', 'product_delivery_status', 'gift_delivery_type'];
    public function __construct()
    {
        $this->messages = [
            "level.required" => "Level Required",
            "level.integer" => "Level must be an integer",
            "level.min" => "Level must be at least 0",
            "pin.required" => "Pin Required",
            "pin.string" => "Pin must be a string",
            "gift_delivery_type.required" => "Gift delivery type Required",
            "gift_delivery_type.integer" => "Gift delivery type must be an integer",
            "gift_delivery_type.in" => "Gift delivery type must be 1 or 2",
            "gift_delivery_address.string" => "Gift delivery address must be a string",
            "gift_delivery_address.max" => "Gift delivery address must be at most 500 characters",
            "wh_number" => "required",
            "wh_number.max" => "WH number must be at most 50 characters",
        ];
    }

    public function dashboard(Request $request)
    {
        try {
            $user = Auth::user();

            // Fetch user financial data
            $userData = $user->only([
                'quiz_total_earning',
                'quiz_total_withdraw',
                'scratch_total_earning',
                'scratch_total_withdraw',
                'saving_total_earning',
                'saving_total_withdraw',
                'current_promoter_level',
            ]);

            // Wallet mappings
            $wallets = [
                'cash_wallet' => $userData['quiz_total_earning'] - $userData['quiz_total_withdraw'],
                'scratch_wallet' => $userData['scratch_total_earning'] - $userData['scratch_total_withdraw'],
                'grow_wallet' => $userData['saving_total_earning'] - $userData['saving_total_withdraw'],
            ];

             // Additional user data
            $totalReferrals = User::where('referred_by', $user->id)->where('is_deleted', 0)->count();
            $activeReferrals = User::where('referred_by', $user->id)
                ->where('is_deleted', 0)
                ->where('is_active', 1)
                ->count();

            // Daily video status
            $dailyVideoController = new DailyVideoController();
            $dailyVideoResponse = $dailyVideoController->todayVideostatus();
            $dailyVideoData = json_decode($dailyVideoResponse->getContent(), true);
            $dailyVideoWatched = $dailyVideoData['data']['watched'] ?? 0;

            // Training video status
            $trainingController = new UserTrainingController();
            $trainingResponse = $trainingController->getCurrentTrainingVideo();
            $trainingData = json_decode($trainingResponse->getContent(), true);
            $training = $trainingData['data']['training'] ?? null;
            $trainingStatus = $trainingData['data']['training_status'] ?? 0;
            $trainingAvailable = $training ? 1 : 0;
            $finalTrainingStatus = ($trainingStatus == 2) ? 2 : $trainingAvailable;

            $dashboardData = array_merge($userData, $wallets, [
                'daily_video_watched' => $dailyVideoWatched,
                'training_status' => $finalTrainingStatus,
                'total_referrals' => $totalReferrals,
                'active_referrals' => $activeReferrals,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data retrieved successfully',
                'data' => $dashboardData,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Dashboard API failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
    public function index(Request $request)
    {
        $this->applyLifecycleAutomation();

        $sort_column = $request->query('sort_column', $request->query('sortBy', 'created_at'));
        $sort_direction = strtoupper($request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
        $page_size = (int) $request->query('page_size', 10);
        $page_number = (int) $request->query('page_number', 1);
        $query = $this->buildPromoterIndexQuery($request);
        $total_records = $query->count();

        // Apply sorting and pagination
        $user_promoters = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            })
            ->with('user')
            ->get()
            ->map(function ($user_promoter) {
                $user_promoter->created_at_formatted = $user_promoter->created_at
                    ? $user_promoter->created_at->format('d-m-Y h:i A')
                    : '-';
                $user_promoter->updated_at_formatted = $user_promoter->updated_at
                    ? $user_promoter->updated_at->format('d-m-Y h:i A')
                    : '-';
                return $user_promoter;
            });

        // Build the response
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $user_promoters,
            'pageInfo' => [
                'page_size' => $page_size,
                'page_number' => $page_number,
                'total_pages' => $page_size > 0 ? ceil($total_records / $page_size) : 1,
                'total_records' => $total_records
            ]
        ], 200);
    }

    private function buildPromoterIndexQuery(Request $request)
    {
        $search_term = $request->query('search', '');
        $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

        $query = UserPromoter::query()->where('is_deleted', 0);

        foreach ($search_param as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array($key, $this->filterable, true)) {
                if (is_array($value)) {
                    if (!empty($value)) {
                        $query->whereIn($key, $value);
                    }
                } else {
                    if ($key === 'fromdate' || $key === 'todate') {
                        continue;
                    }
                    $query->where($key, $value);
                }
            }
        }

        $fromDate = $search_param['fromdate'] ?? null;
        $toDate = $search_param['todate'] ?? null;
        if ($fromDate && $toDate) {
            $query->whereBetween('created_at', [$fromDate, $toDate]);
        } elseif ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        } elseif ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        if (!empty($search_term)) {
            $query->where(function ($q) use ($search_term) {
                $q->where('level', 'LIKE', '%' . $search_term . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search_term) {
                        $userQuery->where('username', 'LIKE', '%' . $search_term . '%')
                            ->orWhere('first_name', 'LIKE', '%' . $search_term . '%')
                            ->orWhere('last_name', 'LIKE', '%' . $search_term . '%')
                            ->orWhere('mobile', 'LIKE', '%' . $search_term . '%');
                    });
            });
        }

        return $query;
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
        try {
            $authId = Auth::id();
            $this->applyLifecycleAutomation($authId);

            // validation pending already request available check
            $validator = Validator::make($request->all(), [
                'level' => 'required|integer|min:0',

            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $promoter = UserPromoter::whereIn('status', [
                UserPromoter::PIN_STATUS_PENDING,
                UserPromoter::PIN_STATUS_APPROVED,
            ])->where('is_deleted', 0)->where('user_id', $authId)->latest('id')->first();

            if (empty($promoter)) {
                DB::beginTransaction();
                $promoter = new UserPromoter();
                $promoter->user_id = $authId;
                $promoter->level = $request->level;
                $promoter->status = UserPromoter::PIN_STATUS_PENDING;
                $promoter->created_by = $authId;
                $promoter->updated_by = $authId;
                $promoter->save();
                DB::commit();
            }

            return response()->json([
                'success' => true,
                'message' => 'User Promoter created successfully',
                'data' => $promoter,
            ], 200);
        
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('UserPromoter store failed', ['error' => $e->getMessage()]);
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
    public function termRaised(Request $request)
    {
        $this->applyLifecycleAutomation();
        $promoter = UserPromoter::find($request->id);

        if (!$promoter || $promoter->is_deleted || $promoter->status !== UserPromoter::PIN_STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $promoter->term_raised_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        $this->syncUserPromoterStatus($promoter->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Term Raised successfully',
        ], 200);
    }

    public function termrAccepted(Request $request)
    {
        $this->applyLifecycleAutomation(Auth::id());
        $promoter = UserPromoter::find($request->id);

        if (
            !$promoter ||
            $promoter->is_deleted ||
            $promoter->user_id !== Auth::id() ||
            $promoter->status !== UserPromoter::PIN_STATUS_PENDING ||
            empty($promoter->term_raised_at)
        ) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $promoter->terms_accepted_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        $this->syncUserPromoterStatus($promoter->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Term Accepted successfully',
        ], 200);
    }

    public function generatePin(Request $request)
    {
        $this->applyLifecycleAutomation();
        $promoter = UserPromoter::find($request->id);

        if (
            !$promoter ||
            $promoter->is_deleted ||
            $promoter->status !== UserPromoter::PIN_STATUS_PENDING
        ) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        if (empty($promoter->terms_accepted_at)) {
            return response()->json(['success' => false, 'message' => 'Terms must be accepted before PIN generation'], 400);
        }

        $promoter->pin = strtoupper('PROM' . rand(1000, 9999));
        $promoter->status = UserPromoter::PIN_STATUS_APPROVED;
        $promoter->pin_generated_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        $this->syncUserPromoterStatus($promoter->user_id);

        return response()->json([
            'success' => true,
            'message' => 'PIN generated successfully',
            'data' => $promoter,
        ], 200);
    }
    public function pinRejected(Request $request)
    {
        $this->applyLifecycleAutomation();
        $promoter = UserPromoter::find($request->id);
        if (!$promoter || $promoter->is_deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }
        $promoter->status = UserPromoter::PIN_STATUS_REJECTED;
        $promoter->updated_by = Auth::id();
        $promoter->save();
        $this->syncUserPromoterStatus($promoter->user_id);
        return response()->json([
            'success' => true,
            'message' => 'PIN rejected successfully',
            'data' => $promoter,
        ], 200);
    }
    /**
     * Activate promoter plan using PIN (user action).
     */
    public function activatePin(Request $request, LevelIncomePayoutService $levelIncomePayoutService)
    {
        try {
            $this->applyLifecycleAutomation(Auth::id());
            $validator = Validator::make($request->all(), [
                'pin' => 'required|string',
                'gift_delivery_type' => 'required|integer|in:1,2',
                'gift_delivery_address' => 'nullable|string|max:500',
                'wh_number' => 'nullable|max:50',

            ], $this->messages);

            $auth_user_id = Auth::id();
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $promoter = UserPromoter::where('id', $request->id)
                ->where('user_id', $auth_user_id)
                ->first();

            if (!$promoter) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Promoter not found'], 400);
            }

            if ($promoter->pin !== $request->pin || $promoter->status != UserPromoter::PIN_STATUS_APPROVED) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Invalid PIN or not approved'], 400);
            }

            $promoter->status = UserPromoter::PIN_STATUS_ACTIVATED;
            $promoter->gift_delivery_type = $request->gift_delivery_type;
            $promoter->direct_pick_date = $request->direct_pick_date;
            $promoter->gift_delivery_address = $request->gift_delivery_address;
            $promoter->wh_number = $request->wh_number;
            $promoter->activated_at = now();
            $promoter->updated_by = $auth_user_id;
            $promoter->save();

            $user = User::find($promoter->user_id);
            $user->current_promoter_level = $promoter->level;
            $user->promoter_status = User::PROMOTER_STATUS_ACTIVATED;
            $user->promoter_activated_at = now();
            $user->save();

            $referrer = $user->referrer;
            if ($referrer) {
                $referred_user = User::find($referrer->id);
                if (isset($referred_user->current_promoter_level) && $referred_user->current_promoter_level >= $user->current_promoter_level) {
                    $scratch_level = ReferralScratchLevel::where(
                        'promotor_level',
                        $user->current_promoter_level
                    )->where('is_active', 1)
                        ->where('is_deleted', 0)->first();
                    if ($scratch_level) {
                        $parent_total_referrals_insame_level = User::where('referred_by', $referrer->id)
                            ->where('current_promoter_level', $user->current_promoter_level)
                            ->where('is_active', 1)
                            ->where('is_deleted', 0)
                            ->where('promoter_status', User::PROMOTER_STATUS_ACTIVATED)
                            ->count();
                        $scratch_range = ReferralScratchRange::where(
                            'referral_scratch_level_id',
                            $scratch_level->id
                        )
                            ->where('is_active', 1)
                            ->where('is_deleted', 0)
                            ->where('start_range', '<=', $parent_total_referrals_insame_level)
                            ->where('end_range', '>=', $parent_total_referrals_insame_level)
                            ->first();
                        if ($scratch_range) {
                            $scratchCard = new ScratchCard();
                            $scratchCard->user_id = $referrer->id;
                            $scratchCard->child_id = $user->id;
                            $scratchCard->is_copy = 0;
                            $scratchCard->is_scratched = 0;
                            $scratchCard->amount = $scratch_range->amount;
                            $scratchCard->notification_msg = 'from ' . $user->username . ' ' . 'upgraded to ' . $user->current_promoter_level;
                            $scratchCard->msg = $scratch_range->msg;
                            $scratchCard->created_by = $auth_user_id;
                            $scratchCard->updated_by = $auth_user_id;
                            $scratchCard->save();

                            $duplicate_getter = AdditionalScratchReferral::where('is_active', 1)
                                ->where('is_all_user', 1)
                                ->where('is_deleted', 0)->get();

                            foreach ($duplicate_getter as $duplicate) {
                                $scratchCard = new ScratchCard();
                                $scratchCard->user_id = $duplicate->userid;
                                $scratchCard->child_id = $user->id;
                                $scratchCard->is_copy = 1;
                                $scratchCard->is_scratched = 0;
                                $scratchCard->amount = $scratch_range->amount;
                                $scratchCard->notification_msg = 'cloned card from ' . $user->username . ' ' . 'upgraded to ' . $user->current_promoter_level;
                                $scratchCard->msg = $scratch_range->msg;
                                $scratchCard->created_by = $auth_user_id;
                                $scratchCard->updated_by = $auth_user_id;
                                $scratchCard->save();
                            }
                        }
                    }
                }
            }

            $levelIncomePayoutService->distributeForPromoterActivation($user, $promoter->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promoter plan activated',
                'data' => $promoter,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('UserPromoter activatePin failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }
    /**
     * Get all promoters for the authenticated user, latest first
     */
    public function userPromotersList()
    {
        $userId = Auth::id();
        $this->applyLifecycleAutomation($userId);

        $promoters = UserPromoter::with('user')
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'User promoters list',
            'data' => $promoters
        ], 200);
    }

    public function exportExcel(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'created_at'));
            $sort_direction = strtoupper($request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $rows = $this->buildPromoterIndexQuery($request)
                ->with('user')
                ->orderBy($sort_column, $sort_direction)
                ->get();

            return Excel::download(
                new PinRequestExport($rows),
                'pin_requests_' . date('Y-m-d_H-i-s') . '.xlsx'
            );
        } catch (\Throwable $e) {
            Log::error('Pin request export failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to export data'], 500);
        }
    }

    public function productDeliveryStatusUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'product_delivery_status' => 'required|integer|in:0,1,2,3',
            'product_delivery_notes' => 'nullable|string|max:2000',
            'bill_path' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promoter = UserPromoter::find($request->id);
        if (!$promoter) {
            return response()->json(['success' => false, 'message' => 'Promoter not found'], 400);
        }

        $promoter->product_delivery_status = (int) $request->product_delivery_status;
        $promoter->product_delivery_notes = $request->product_delivery_notes;
        $promoter->bill_path = $request->bill_path;
        $promoter->product_delivery_updated_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        return response()->json(['success' => true, 'message' => 'Product delivery status updated successfully', 'data' => $promoter], 200);
    }

    public function customerDeliveryConfirmation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'customer_delivery_status' => 'required|integer|in:1,2',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promoter = UserPromoter::where('id', $request->id)->where('user_id', Auth::id())->first();
        if (!$promoter) {
            return response()->json(['success' => false, 'message' => 'Promoter not found'], 400);
        }

        $promoter->customer_delivery_status = (int) $request->customer_delivery_status;
        $promoter->customer_delivery_confirmed_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        return response()->json(['success' => true, 'message' => 'Product receipt status saved successfully', 'data' => $promoter], 200);
    }

    private function applyLifecycleAutomation(?int $userId = null): void
    {
        $this->autoRaiseTerms($userId);
        $this->autoDeleteExpiredPendingRequests($userId);
    }

    private function autoRaiseTerms(?int $userId = null): void
    {
        $query = UserPromoter::query()
            ->where('is_deleted', 0)
            ->where('status', UserPromoter::PIN_STATUS_PENDING)
            ->whereNull('term_raised_at')
            ->where('created_at', '<=', now()->subMinutes(10));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $promoters = $query->get();
        foreach ($promoters as $promoter) {
            $promoter->term_raised_at = now();
            $promoter->save();
            $this->syncUserPromoterStatus($promoter->user_id);
        }
    }

    private function autoDeleteExpiredPendingRequests(?int $userId = null): void
    {
        $query = UserPromoter::query()
            ->where('is_deleted', 0)
            ->where('status', UserPromoter::PIN_STATUS_PENDING)
            ->where('created_at', '<=', now()->subDays(3));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $promoters = $query->get();
        foreach ($promoters as $promoter) {
            $promoter->status = UserPromoter::PIN_STATUS_AUTO_DELETED;
            $promoter->is_active = 0;
            $promoter->is_deleted = 1;
            $promoter->auto_deleted_at = now();
            $promoter->deleted_reason = 'Automatically deleted after 3 days without admin action';
            $promoter->save();
            $this->syncUserPromoterStatus($promoter->user_id);
        }
    }

    private function syncUserPromoterStatus(int $userId): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        if ($user->current_promoter_level !== null) {
            $user->promoter_status = User::PROMOTER_STATUS_ACTIVATED;
            $user->save();
            return;
        }

        $activeRequest = UserPromoter::query()
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first();

        if (!$activeRequest) {
            $user->promoter_status = null;
        } elseif ($activeRequest->status === UserPromoter::PIN_STATUS_APPROVED) {
            $user->promoter_status = User::PROMOTER_STATUS_APPROVED;
        } elseif (!empty($activeRequest->terms_accepted_at)) {
            $user->promoter_status = User::PROMOTER_STATUS_ACCEPTED_TERM;
        } elseif (!empty($activeRequest->term_raised_at)) {
            $user->promoter_status = User::PROMOTER_STATUS_SHOW_TERM;
        } elseif ($activeRequest->status === UserPromoter::PIN_STATUS_REJECTED) {
            $user->promoter_status = User::PROMOTER_STATUS_REJECTED;
        } else {
            $user->promoter_status = User::PROMOTER_STATUS_PENDING;
        }

        $user->save();
    }
    public function getScratchCards()
    {
        $userId = Auth::id();

        $scratchCards = ScratchCard::with('user')
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'User scratch cards list',
            'data' => $scratchCards
        ], 200);
    }
    public function scratchedStatusUpdate(Request $request)
    {
        $userId = Auth::id();

        $scratchCard = ScratchCard::find($request->scratch_card_id);
        if (!$scratchCard || $scratchCard->user_id != $userId || $scratchCard->is_scratched == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Scratch card not found or already scratched',
            ], 404);
        }
        $scratchCard->is_scratched =1;
        $scratchCard->save();
            // saving earning history
        $saving_earning_history = new EarningHistory();
        $saving_earning_history->user_id = $userId;
        $saving_earning_history->amount = $scratchCard->amount;
        $saving_earning_history->earning_date = today();
        $saving_earning_history->earning_type = EarningHistory::EARNING_TYPE_SCRATCH_EARNING;
        $saving_earning_history->description = $scratchCard->notification_msg;
        $saving_earning_history->earning_status = 1;
        $saving_earning_history->save();
        $user = User::find($scratchCard->user_id);
        $user->scratch_total_earning += $scratchCard->amount;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Scratch card scratched successfully',
            'data' => $scratchCard
        ], 200);
    }
}
