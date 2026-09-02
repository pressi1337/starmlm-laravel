<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Product price master — one sales price + MRP per promoter level.
 *
 * Every dispatch bills at the price set here for the promoter's level. The
 * value is copied onto the box request at dispatch, so editing a price here
 * affects future invoices only.
 */
class ProductPriceController extends Controller
{
    /**
     * Every level, whether or not a price has been saved yet, so the admin
     * always sees a complete list to fill in.
     */
    public function index()
    {
        $saved = ProductPrice::where('is_deleted', 0)
            ->get()
            ->keyBy(fn ($row) => (int) $row->level);

        $rows = [];
        foreach (ProductPrice::LEVELS as $level) {
            $row = $saved->get($level);
            $rows[] = [
                'level'        => $level,
                'level_label'  => ProductPrice::levelLabel($level),
                'product_name' => $row->product_name ?? (ProductPrice::DEFAULT_NAMES[$level] ?? ''),
                'price'        => $row ? (float) $row->price : null,
                'mrp'          => $row ? (float) $row->mrp : null,
                'is_active'    => $row ? (int) $row->is_active : 1,
                'configured'   => (bool) $row,
            ];
        }

        return response()->json(['success' => true, 'data' => $rows], 200);
    }

    /**
     * Save one level's price. Upsert keyed on the level, so the admin edits
     * the same row rather than stacking duplicates.
     */
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'level'        => 'required|integer|in:' . implode(',', ProductPrice::LEVELS),
            'product_name' => 'required|string|max:120',
            'price'        => 'required|numeric|min:0',
            'mrp'          => 'required|numeric|min:0',
            'is_active'    => 'nullable|boolean',
        ], [
            'level.in'             => 'Unknown promoter level',
            'product_name.required' => 'Product name is required',
            'price.required'       => 'Sales price is required',
            'mrp.required'         => 'MRP is required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $level = (int) $request->input('level');
            $record = ProductPrice::where('level', $level)->where('is_deleted', 0)->first();

            if (!$record) {
                $record = new ProductPrice();
                $record->level = $level;
                $record->created_by = Auth::id();
            }

            $record->product_name = trim((string) $request->input('product_name'));
            $record->price = round((float) $request->input('price'), 2);
            $record->mrp = round((float) $request->input('mrp'), 2);
            $record->is_active = $request->has('is_active')
                ? (int) (bool) $request->input('is_active')
                : 1;
            $record->is_deleted = 0;
            $record->updated_by = Auth::id();
            $record->save();

            return response()->json([
                'status'  => true,
                'message' => 'Price saved successfully',
                'data'    => $record,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('ProductPrice upsert failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}
