<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\BillTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Bill template — the seller side of the plan-product invoice.
 *
 * Single-document admin CRUD, the same shape as Terms & Conditions:
 * `upsert()` updates the latest non-deleted row (creating one on first save),
 * and every generated invoice reads from it.
 */
class BillTemplateController extends Controller
{
    /**
     * The current template, or `{ data: null }` when the admin hasn't set one
     * up yet. Used by the admin form to pre-fill.
     */
    public function show()
    {
        return response()->json([
            'success' => true,
            'data'    => BillTemplate::current(),
        ], 200);
    }

    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name'      => 'required|string|max:190',
            'address_line1'     => 'nullable|string|max:190',
            'address_line2'     => 'nullable|string|max:190',
            'city'              => 'nullable|string|max:120',
            'state'             => 'nullable|string|max:120',
            'pincode'           => 'nullable|string|max:20',
            // GSTIN is 15 chars; kept loose so an unusual one isn't rejected,
            // but long enough to be obviously wrong if mistyped.
            'gstin'             => 'nullable|string|max:20',
            'pan'               => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:190',
            'phone'             => 'nullable|string|max:30',
            'invoice_prefix'    => 'nullable|string|max:20',
            'place_of_supply'   => 'nullable|string|max:120',
            'country_of_supply' => 'nullable|string|max:120',
        ], [
            'company_name.required' => 'Company name is required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $authId = Auth::id();
            $record = BillTemplate::current();

            if (!$record) {
                $record = new BillTemplate();
                $record->created_by = $authId;
            }

            foreach ([
                'company_name', 'address_line1', 'address_line2', 'city', 'state',
                'pincode', 'gstin', 'pan', 'email', 'phone', 'invoice_prefix',
                'place_of_supply',
            ] as $field) {
                $record->{$field} = $request->input($field);
            }
            $record->country_of_supply = $request->input('country_of_supply') ?: 'India';
            $record->is_active = 1;
            $record->updated_by = $authId;
            $record->save();

            return response()->json([
                'status'  => true,
                'message' => 'Bill template saved successfully',
                'data'    => $record,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BillTemplate upsert failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}
