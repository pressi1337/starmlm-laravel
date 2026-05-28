<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBankDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserBankDetailController extends Controller
{
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'acc_no' => 'required|string|max:100',
            'acc_name' => 'required|string|max:100',
            'ifsc_code' => 'required|string|max:20',
            'bank_name' => 'required|string|max:150',
            'branch_name' => 'required|string|max:150',
            'address' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = Auth::id();

        $data = [
            'user_id' => $userId,
            'acc_no' => $request->acc_no,
            'acc_name' => $request->acc_name,
            'ifsc_code' => $request->ifsc_code,
            'bank_name' => $request->bank_name,
            'branch_name' => $request->branch_name,
            'address' => $request->address,
            'is_editable' => 1,
        ];

        $record = UserBankDetail::updateOrCreate(['user_id' => $userId], $data);

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => $record,
        ], 200);
    }

    public function show()
    {
        $userId = Auth::id();
        $record = UserBankDetail::where('user_id', $userId)->first();

        return response()->json([
            'status' => true,
            'data' => $record,
        ], 200);
    }

    /**
     * Admin-only: wipe a user's saved bank details and re-open the form for
     * editing. Nulls every bank field and flips `is_editable` back to 0 so the
     * PWA Profile screen unlocks (it treats `is_editable = 1` as "locked").
     * Returns 404 if the user has no record yet — nothing to clear.
     */
    public function clearForUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $userId = (int) $request->user_id;

        try {
            $record = UserBankDetail::where('user_id', $userId)->first();
            if (!$record) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No bank details found for this user',
                ], 404);
            }

            $record->acc_no       = null;
            $record->acc_name     = null;
            $record->ifsc_code    = null;
            $record->bank_name    = null;
            $record->branch_name  = null;
            $record->address      = null;
            $record->is_editable  = 0;
            $record->save();

            return response()->json([
                'status'  => true,
                'message' => 'Bank details cleared. User can now add fresh details.',
                'data'    => $record,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('UserBankDetail clearForUser failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }
}
