<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\TermsAndCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Terms & Conditions — single-document admin CRUD.
 *
 * The product only ever has ONE active T&C document. `upsert()` updates the
 * latest non-deleted row (creating one on first save). Both the admin form
 * and the PWA reader call `show()` to read the current content.
 */
class TermsAndConditionController extends Controller
{
    /**
     * Returns the latest non-deleted T&C row, or `{ data: null }` if none
     * exists yet. Used by the admin editor (to pre-fill) and the PWA page
     * (to render to the user). Public — T&C content is meant to be visible.
     */
    public function show()
    {
        $record = TermsAndCondition::where('is_deleted', 0)
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $record,
        ], 200);
    }

    /**
     * Admin upsert. If a row exists, update its content; otherwise insert
     * a fresh row. Either way, return the resulting record.
     */
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ], [
            'content.required' => 'Content is required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $authId = Auth::id();
            $record = TermsAndCondition::where('is_deleted', 0)
                ->orderBy('id', 'desc')
                ->first();

            if (!$record) {
                $record = new TermsAndCondition();
                $record->created_by = $authId;
            }
            $record->content = $request->content;
            $record->is_active = 1;
            $record->updated_by = $authId;
            $record->save();

            return response()->json([
                'status'  => true,
                'message' => 'Terms & Conditions saved successfully',
                'data'    => $record,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TermsAndCondition upsert failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }
}
