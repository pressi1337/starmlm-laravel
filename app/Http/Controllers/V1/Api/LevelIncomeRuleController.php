<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\LevelIncomeRule;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LevelIncomeRuleController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['created_at', 'promoter_level', 'referral_depth', 'amount', 'wallet_type'];
    protected array $filterable = ['promoter_level', 'referral_depth', 'wallet_type', 'trigger_type', 'is_active'];

    public function index(Request $request)
    {
        try {
            $sortColumn = $request->query('sort_column', 'promoter_level');
            $sortDirection = strtoupper($request->query('sort_direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
            $pageSize = max(0, (int) $request->query('page_size', 50));
            $pageNumber = max(1, (int) $request->query('page_number', 1));
            $searchTerm = trim((string) $request->query('search', ''));
            $searchParam = $this->safeJsonDecode($request->query('search_param', '{}'));

            if (!in_array($sortColumn, $this->sortable, true)) {
                $sortColumn = 'promoter_level';
            }

            $query = LevelIncomeRule::query()->where('is_deleted', 0);

            foreach (($searchParam ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }

                if (in_array($key, $this->filterable, true)) {
                    $query->where($key, $value);
                }
            }

            if ($searchTerm !== '') {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('promoter_level', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('referral_depth', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhere('amount', 'LIKE', '%' . $searchTerm . '%');
                });
            }

            $totalRecords = $query->count();

            $items = $query->orderBy($sortColumn, $sortDirection)
                ->when($pageSize > 0, function ($q) use ($pageSize, $pageNumber) {
                    return $q->skip(($pageNumber - 1) * $pageSize)->take($pageSize);
                })
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $items,
                'pageInfo' => [
                    'page_size' => $pageSize,
                    'page_number' => $pageNumber,
                    'total_pages' => $pageSize > 0 ? (int) ceil($totalRecords / max(1, $pageSize)) : 1,
                    'total_records' => $totalRecords,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Level income rule index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'promoter_level' => 'required|integer|min:0',
            'referral_depth' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'trigger_type' => 'nullable|integer|min:1',
            'wallet_type' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $exists = LevelIncomeRule::query()
            ->where('promoter_level', $request->promoter_level)
            ->where('referral_depth', $request->referral_depth)
            ->where('trigger_type', $request->input('trigger_type', LevelIncomeRule::TRIGGER_TYPE_PROMOTER_ACTIVATION))
            ->where('wallet_type', $request->input('wallet_type', 1))
            ->where('is_deleted', 0)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Rule already exists for this promoter level and referral depth'], 422);
        }

        $rule = LevelIncomeRule::create([
            'promoter_level' => $request->promoter_level,
            'referral_depth' => $request->referral_depth,
            'amount' => $request->amount,
            'trigger_type' => $request->input('trigger_type', LevelIncomeRule::TRIGGER_TYPE_PROMOTER_ACTIVATION),
            'wallet_type' => $request->input('wallet_type', 1),
            'is_active' => $request->has('is_active') ? (int) $request->boolean('is_active') : 1,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Level income rule created successfully',
            'data' => $rule,
        ], 200);
    }

    public function show(string $id)
    {
        $rule = LevelIncomeRule::query()->where('id', $id)->where('is_deleted', 0)->first();

        return response()->json([
            'success' => true,
            'data' => $rule,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'promoter_level' => 'required|integer|min:0',
            'referral_depth' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'trigger_type' => 'nullable|integer|min:1',
            'wallet_type' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rule = LevelIncomeRule::query()->where('id', $id)->where('is_deleted', 0)->first();

        if (!$rule) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $duplicate = LevelIncomeRule::query()
            ->where('id', '!=', $rule->id)
            ->where('promoter_level', $request->promoter_level)
            ->where('referral_depth', $request->referral_depth)
            ->where('trigger_type', $request->input('trigger_type', $rule->trigger_type))
            ->where('wallet_type', $request->input('wallet_type', $rule->wallet_type))
            ->where('is_deleted', 0)
            ->exists();

        if ($duplicate) {
            return response()->json(['success' => false, 'message' => 'Rule already exists for this promoter level and referral depth'], 422);
        }

        $rule->promoter_level = $request->promoter_level;
        $rule->referral_depth = $request->referral_depth;
        $rule->amount = $request->amount;
        $rule->trigger_type = $request->input('trigger_type', $rule->trigger_type);
        $rule->wallet_type = $request->input('wallet_type', $rule->wallet_type);
        $rule->is_active = $request->has('is_active') ? (int) $request->boolean('is_active') : $rule->is_active;
        $rule->updated_by = Auth::id();
        $rule->save();

        return response()->json([
            'success' => true,
            'message' => 'Level income rule updated successfully',
            'data' => $rule,
        ], 200);
    }

    public function destroy(string $id)
    {
        $rule = LevelIncomeRule::query()->where('id', $id)->where('is_deleted', 0)->first();

        if (!$rule) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $rule->is_deleted = 1;
        $rule->updated_by = Auth::id();
        $rule->save();

        return response()->json([
            'success' => true,
            'message' => 'Level income rule deleted successfully',
        ], 200);
    }

    public function statusUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rule = LevelIncomeRule::query()->where('id', $request->id)->where('is_deleted', 0)->first();

        if (!$rule) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $rule->is_active = (int) $request->boolean('is_active');
        $rule->updated_by = Auth::id();
        $rule->save();

        return response()->json([
            'success' => true,
            'message' => 'Level income rule updated successfully',
            'data' => $rule,
        ], 200);
    }
}
