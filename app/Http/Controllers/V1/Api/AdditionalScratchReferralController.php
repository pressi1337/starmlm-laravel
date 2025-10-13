<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\AdditionalScratchReferral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdditionalScratchReferralController extends Controller
{
    /**
     * Upsert an AdditionalScratchReferral
     * If id is provided, update that record; otherwise create a new one.
     */
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'referral_code' => 'required',
            'is_active' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $userid = DB::table('users')
            ->where('referral_code', $request->referral_code)
            ->value('id');
        if (is_null($userid)) {
            return response()->json([
            'success' => false,
            'message' => 'No referral user found. Please cross-check the referral code.'
            ], 400);
            }

        // Build data using the fetched userid and provided fields
        $data = [
            'userid' => $userid,
            'referral_code' => $request->referral_code,
            'is_active' => $request->has('is_active') ? (int) $request->is_active : 1,
        ];
        $id = $request->input('id');

        if ($id) {
            $record = AdditionalScratchReferral::updateOrCreate(['id' => $id], $data);
        } else {
            $record = AdditionalScratchReferral::create($data);
        }

        return response()->json([
            'status' => true,
            'message' => 'Created successfully',
            'data' => $record,
        ], 200);
    }

    /**
     * Fetch a single record by id
     */
    public function show($id)
    {
        $record = AdditionalScratchReferral::find($id);

        return response()->json([
            'status' => true,
            'data' => $record,
        ], 200);
    }
}
