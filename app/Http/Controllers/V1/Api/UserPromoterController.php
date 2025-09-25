<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPromoter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserPromoterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        $request->validate([
            'level' => 'required|integer|min:1|max:4',
        ]);

        try {
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
            // $user = User::find($promoter->user_id);
            // $user->current_promoter_level = $promoter->level;
            // $user->promoter_status = UserPromoter::PIN_STATUS_PENDING;
            // $user->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User Promoter created successfully',
                'data' => $promoter,
            ], 201);
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
    public function generatePin(Request $request)
    {
        $promoter = UserPromoter::find($request->id);

        if (!$promoter || $promoter->is_deleted) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $promoter->pin = strtoupper('PROM' . rand(1000, 9999));
        $promoter->status = UserPromoter::PIN_STATUS_APPROVED;
        $promoter->pin_generated_at = now();
        $promoter->updated_by = Auth::id();
        $promoter->save();

        $user = User::find($promoter->user_id);
        $user->current_promoter_level = $promoter->level;
        $user->promoter_status = UserPromoter::PIN_STATUS_APPROVED;
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
        $request->validate([
            'pin' => 'required|string',
            'gift_delivery_type' => 'required|integer|in:1,2',
            'gift_delivery_address' => 'nullable|string|max:500',
            'wh_number' => 'nullable|string|max:50',
        ]);

        $promoter = UserPromoter::where('id', $request->id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$promoter) {
            return response()->json(['success' => false, 'message' => 'Promoter not found'], 404);
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
        $user->promoter_status = UserPromoter::PIN_STATUS_ACTIVATED;
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
