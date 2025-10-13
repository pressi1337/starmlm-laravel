<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPromoter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserPromoterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $messages;
    public function __construct()
    {
        $this->messages = [
            "level.required" => "Level Required",
            "level.integer" => "Level must be an integer",
            "level.min" => "Level must be at least 1",
            "level.max" => "Level must be at most 4",
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
    public function index(Request $request)
    {

        // Default sorting
        $sort_column = $request->query('sort_column', 'created_at');
        $sort_direction = $request->query('sort_direction', 'DESC');

        // Pagination parameters
        $page_size = (int) $request->query('page_size', 10); // Default to 10 items per page
        $page_number = (int) $request->query('page_number', 1);
        $search_term = $request->query('search', '');

        // Parse search_param JSON
        $search_param = $request->query('search_param', '{}');
        try {
            $search_param = json_decode($search_param, true);
            if (!is_array($search_param)) {
                $search_param = [];
            }
        } catch (\Exception $e) {
            $search_param = [];
        }

        // Start building the query
        $query = UserPromoter::query();

        // Apply default filters
        $query->where('is_deleted', 0);

        // Apply search_param filters
        foreach ($search_param as $key => $value) {
            if (is_array($value)) {
                if (!empty($value)) {
                    // Use whereIn for array values
                    $query->whereIn($key, $value);
                }
            } else {
                if ($value !== '') {
                    // Use where for single values
                    $query->where($key, $value);
                }
            }
        }

        // Apply search filter on title and description
        if (!empty($search_term)) {
            $query->where(function ($q) use ($search_term) {
                $q->where('level', 'LIKE', '%' . $search_term . '%');
            });
        }

        // Get total records for pagination
        $total_records = $query->count();

        // Apply sorting and pagination
        $user_promoters = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                return $q->skip(($page_number - 1) * $page_size)
                    ->take($page_size);
            })->with('user')
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
            // validation pending already request available check
            // Validation
            $validator = Validator::make($request->all(), [
                'level' => 'required|integer|min:0|max:4',

            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            DB::beginTransaction();
            $authId = Auth::id();
            $promoter = new UserPromoter();
            $promoter->user_id = $authId;
            $promoter->level = $request->level;
            $promoter->status = UserPromoter::PIN_STATUS_PENDING;
            $promoter->created_by = $authId;
            $promoter->updated_by = $authId;
            $promoter->save();
            // confirm to enable
            $user = User::find($authId);
            $user->current_promoter_level = $promoter->level;
            $user->promoter_status = UserPromoter::PIN_STATUS_PENDING;
            $user->save();
            DB::commit();

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
        $promoter = UserPromoter::find($request->id);

        if (!$promoter || $promoter->is_deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $user = User::find($promoter->user_id);
        $user->promoter_status = UserPromoter::PROMOTER_STATUS_SHOW_TERM;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Term Raised successfully',
        ], 200);
    }

    public function termrAccepted(Request $request)
    {
        $promoter = UserPromoter::find($request->id);

        if (!$promoter || $promoter->is_deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $user = User::find($promoter->user_id);
        $user->promoter_status = UserPromoter::PROMOTER_STATUS_ACCEPTED_TERM;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Term Accepted successfully',
        ], 200);
    }

    public function generatePin(Request $request)
    {
        $promoter = UserPromoter::find($request->id);

        if (!$promoter || $promoter->is_deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $promoter->pin = strtoupper('PROM' . rand(1000, 9999));
        $promoter->status = UserPromoter::PIN_STATUS_APPROVED;
        $promoter->pin_generated_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        $user = User::find($promoter->user_id);
        $user->current_promoter_level = $promoter->level;
        $user->promoter_status = UserPromoter::PROMOTER_STATUS_APPROVED;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'PIN generated successfully',
            'data' => $promoter,
        ], 200);
    }
    /**
     * Activate promoter plan using PIN (user action).
     */
    public function activatePin(Request $request)
    {
      
        $validator = Validator::make($request->all(), [
            'pin' => 'required|string',
            'gift_delivery_type' => 'required|integer|in:1,2',
            'gift_delivery_address' => 'nullable|string|max:500',
            'wh_number' => 'nullable|max:50',

        ], $this->messages);
        // after 25 days 
        //igf gift_delivery_type ==1 date :address
        // auth user =10
        // refer parent user =2

        // scratch card for 2
        // 2= promoter level
        // // auth user activate level(0,1,2,3,4) <= parent user user level 1
        // how many refers based to get range
        // scratch assign
        // copy to duba


        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $promoter = UserPromoter::where('id', $request->id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$promoter) {
            return response()->json(['success' => false, 'message' => 'Promoter not found'], 400);
        }

        if ($promoter->pin !== $request->pin || $promoter->status != UserPromoter::PIN_STATUS_APPROVED) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN or not approved'], 400);
        }

        $promoter->status = UserPromoter::PIN_STATUS_ACTIVATED;
        $promoter->gift_delivery_type = $request->gift_delivery_type;
        $promoter->gift_delivery_address = $request->gift_delivery_address;
        $promoter->wh_number = $request->wh_number;
        $promoter->activated_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();
        $user = User::find($promoter->user_id);
        $user->current_promoter_level = $promoter->level;
        $user->promoter_status = UserPromoter::PROMOTER_STATUS_CLOSED;
        $user->promoter_activated_at = now();
        $user->save();
        return response()->json([
            'success' => true,
            'message' => 'Promoter plan activated',
            'data' => $promoter,
        ], 200);
    }
    /**
     * Get all promoters for the authenticated user, latest first
     */
    public function userPromotersList()
    {
        $userId = Auth::id();

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
}
