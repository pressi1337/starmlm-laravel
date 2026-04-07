<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportHelpItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportHelpController extends Controller
{
    public function index(Request $request)
    {
        $query = SupportHelpItem::query()
            ->where('is_deleted', 0);

        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', 1);
        }

        $items = $query
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $items,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = SupportHelpItem::create([
            'question' => $request->question,
            'answer' => $request->answer,
            'sort_order' => $request->sort_order ?? 0,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'is_active' => $request->has('is_active') ? (int) $request->is_active : 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Support help item created successfully',
            'data' => $item,
        ], 200);
    }

    public function show(string $id)
    {
        $item = SupportHelpItem::where('is_deleted', 0)->find($id);

        return response()->json([
            'success' => true,
            'data' => $item,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = SupportHelpItem::where('is_deleted', 0)->find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $item->question = $request->question;
        $item->answer = $request->answer;
        $item->sort_order = $request->sort_order ?? 0;
        $item->is_active = $request->has('is_active') ? (int) $request->is_active : $item->is_active;
        $item->updated_by = Auth::id();
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Support help item updated successfully',
            'data' => $item,
        ], 200);
    }

    public function destroy(string $id)
    {
        $item = SupportHelpItem::find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Not found'], 400);
        }

        $item->is_deleted = 1;
        $item->updated_by = Auth::id();
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Support help item deleted successfully',
        ], 200);
    }

    public function statusUpdate(Request $request)
    {
        $item = SupportHelpItem::find($request->id);
        if (!$item) {
            return response()->json(['message' => 'Data not found', 'status' => 400], 400);
        }

        $item->is_active = $request->has('is_active') ? (int) $request->is_active : 1;
        $item->updated_by = Auth::id();
        $item->save();

        return response()->json(['message' => 'Support help status updated successfully', 'status' => 200]);
    }
}
