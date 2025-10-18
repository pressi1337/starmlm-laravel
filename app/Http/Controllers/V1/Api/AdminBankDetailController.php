<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminBankDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminBankDetailController extends Controller
{
    public function manage(Request $request)
    {
        // Validation rules
        $rules = [
            'account_holder_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'bank_name' => 'required|string|max:255',
            'branch_name' => 'required|string|max:255',
            'ifsc_code' => 'required|string|max:20',
            'whatsapp_number' => 'nullable|string|max:20',
        ];

        // For POST/PUT/PATCH requests, validate and update/insert
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $userId = Auth::id();

        $data = [
            'account_number' => $request->account_number,
            'account_holder_name' => $request->account_holder_name,
            'ifsc_code' => $request->ifsc_code,
            'bank_name' => $request->bank_name,
            'branch_name' => $request->branch_name,
            'whatsapp_number'=>$request->whatsapp_number,
        ];

        $recordold = AdminBankDetail::first();

        // Update or create the record
        $bankDetail = AdminBankDetail::updateOrCreate(
            ['id' => @$recordold->id ?? null],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Bank details saved successfully',
            'data' => $bankDetail
        ], 200);
    }

    // Optional: Separate endpoint to get active bank details
    public function getActive()
    {
        $details = AdminBankDetail::first();
        
        return response()->json([
            'success' => true,
            'data' => $details
        ], 200);
    }
}
