<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSuggestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserSuggestionController extends Controller
{
    public function index(Request $request)
    {
        $authUser = Auth::user();

        $query = UserSuggestion::query()
            ->where('is_deleted', 0)
            ->with('user');

        if ($authUser->role === User::ROLE_USER) {
            $query->where('user_id', $authUser->id);
        }

        $items = $query
            ->orderByDesc('id')
            ->get();

        $pendingCount = UserSuggestion::where('user_id', $authUser->id)
            ->where('status', UserSuggestion::STATUS_PENDING)
            ->where('is_deleted', 0)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $items,
            'meta' => [
                'pending_limit' => 3,
                'pending_count' => $pendingCount,
                'available_slots' => max(0, 3 - $pendingCount),
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'suggestion_text' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userId = Auth::id();
        $pendingCount = UserSuggestion::where('user_id', $userId)
            ->where('status', UserSuggestion::STATUS_PENDING)
            ->where('is_deleted', 0)
            ->count();

        if ($pendingCount >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'You can keep only 3 pending suggestions at a time until admin reacts.',
            ], 400);
        }

        $item = UserSuggestion::create([
            'user_id' => $userId,
            'suggestion_text' => $request->suggestion_text,
            'status' => UserSuggestion::STATUS_PENDING,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Suggestion submitted successfully',
            'data' => $item,
        ], 200);
    }

    public function react(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'admin_response_text' => 'nullable|string|max:2000',
            'admin_reaction_emoji' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = UserSuggestion::where('is_deleted', 0)->find($request->id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $item->admin_response_text = $request->admin_response_text;
        $item->admin_reaction_emoji = $request->admin_reaction_emoji;
        $item->admin_reacted_at = now();
        $item->admin_reacted_by = Auth::id();
        $item->status = UserSuggestion::STATUS_REACTED;
        $item->updated_by = Auth::id();
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Suggestion response saved successfully',
            'data' => $item,
        ], 200);
    }
}
