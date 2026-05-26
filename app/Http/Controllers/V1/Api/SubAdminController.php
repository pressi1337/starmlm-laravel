<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Sub-admin management. Accessible to Super-Admin only (gated at the route
 * layer via `role:0`). Operates exclusively on User rows where role = 1.
 */
class SubAdminController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['created_at', 'updated_at', 'username', 'first_name', 'is_active'];
    protected array $filterable = ['is_active', 'fromdate', 'todate'];

    protected $messages = [
        'first_name.required' => 'First Name Required',
        'last_name.required'  => 'Last Name Required',
        'username.required'   => 'Username Required',
        'password.required'   => 'Password Required',
        'password.min'        => 'Password Must Be At Least 6 Characters',
        'password.confirmed'  => 'Password Confirmation Mismatch',
        'username.unique'     => 'Username Already Exists',
    ];

    /** List sub-admins with the standard search/sort/page contract. */
    public function index(Request $request)
    {
        $sort_column = $request->query('sort_column', 'created_at');
        $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        if (!in_array($sort_column, $this->sortable, true)) {
            $sort_column = 'created_at';
        }

        $page_size = max(0, (int) $request->query('page_size', 10));
        $page_number = max(1, (int) $request->query('page_number', 1));
        $search_term = trim((string) $request->query('search', ''));
        $search_param = $this->safeJsonDecode($request->query('search_param', '{}'));

        $query = User::query()
            ->where('role', User::ROLE_SUB_ADMIN)
            ->where('is_deleted', 0);

        foreach (($search_param ?? []) as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if ($key === 'fromdate' && $value) {
                $query->whereDate('created_at', '>=', $value);
                continue;
            }
            if ($key === 'todate' && $value) {
                $query->whereDate('created_at', '<=', $value);
                continue;
            }
            if (!in_array($key, $this->filterable, true)) {
                continue;
            }
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        if ($search_term !== '') {
            $query->where(function ($q) use ($search_term) {
                $like = '%' . $search_term . '%';
                $q->where('username', 'LIKE', $like)
                  ->orWhere('first_name', 'LIKE', $like)
                  ->orWhere('last_name', 'LIKE', $like);
            });
        }

        $total_records = (clone $query)->count();

        $rows = $query->orderBy($sort_column, $sort_direction)
            ->when($page_size > 0, fn ($q) => $q->skip(($page_number - 1) * $page_size)->take($page_size))
            ->get([
                'id', 'first_name', 'last_name', 'username',
                'is_active', 'role', 'created_at', 'updated_at',
            ])
            ->map(function ($row) {
                $row->created_at_formatted = $row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-';
                $row->updated_at_formatted = $row->updated_at ? $row->updated_at->format('d-m-Y h:i A') : '-';
                return $row;
            });

        return response()->json([
            'success'  => true,
            'message'  => 'Success',
            'data'     => $rows,
            'pageInfo' => [
                'page_size'     => $page_size,
                'page_number'   => $page_number,
                'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max($page_size, 1)) : 1,
                'total_records' => $total_records,
            ],
        ], 200);
    }

    /** Create a sub-admin (role=1). */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'username'   => 'required|string|max:100|unique:users,username',
            'password'   => 'required|string|min:6|confirmed',
            'is_active'  => 'nullable|boolean',
        ], $this->messages);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $actorId = Auth::id();
            $user = new User();
            $user->first_name = $request->first_name;
            $user->last_name  = $request->last_name;
            $user->username   = $request->username;
            $user->password   = Hash::make($request->password);
            $user->pwd_text   = $request->password;
            $user->role       = User::ROLE_SUB_ADMIN;
            $user->is_active  = $request->has('is_active') ? (int) (bool) $request->input('is_active') : 1;
            $user->is_deleted = 0;
            $user->created_by = $actorId;
            $user->updated_by = $actorId;
            $user->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sub-admin created successfully',
                'data'    => $this->present($user),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SubAdmin store failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not create sub-admin',
            ], 500);
        }
    }

    /** Fetch a single sub-admin. */
    public function show(string $id)
    {
        $user = User::where('id', $id)
            ->where('role', User::ROLE_SUB_ADMIN)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->present($user),
        ], 200);
    }

    /** Update a sub-admin. Password is optional (only changed if provided). */
    public function update(Request $request, string $id)
    {
        $user = User::where('id', $id)
            ->where('role', User::ROLE_SUB_ADMIN)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100',
            'last_name'  => 'sometimes|required|string|max:100',
            'username'   => ['sometimes', 'required', 'string', 'max:100', 'unique:users,username,' . $user->id],
            'password'   => 'nullable|string|min:6|confirmed',
            'is_active'  => 'nullable|boolean',
        ], $this->messages);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            foreach (['first_name', 'last_name', 'username'] as $field) {
                if ($request->has($field)) {
                    $user->{$field} = $request->input($field);
                }
            }

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
                $user->pwd_text = $request->password;
                // Invalidate any existing session so the sub-admin must log in again.
                $user->remember_token = null;
            }

            if ($request->has('is_active')) {
                $user->is_active = (int) (bool) $request->input('is_active');
            }

            $user->updated_by = Auth::id();
            $user->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sub-admin updated successfully',
                'data'    => $this->present($user),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SubAdmin update failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not update sub-admin',
            ], 500);
        }
    }

    /** Toggle active flag. PATCH /sub-admins/status-update { id, is_active }. */
    public function statusUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'        => 'required|integer',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('id', $request->id)
            ->where('role', User::ROLE_SUB_ADMIN)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $user->is_active = (int) (bool) $request->is_active;
        $user->updated_by = Auth::id();
        // Disabling a sub-admin should drop their active session.
        if (!$user->is_active) {
            $user->remember_token = null;
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'Sub-admin activated' : 'Sub-admin deactivated',
            'data'    => $this->present($user),
        ], 200);
    }

    /** Soft-delete a sub-admin (is_deleted = 1). */
    public function destroy(string $id)
    {
        $user = User::where('id', $id)
            ->where('role', User::ROLE_SUB_ADMIN)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $user->is_deleted = 1;
        $user->is_active = 0;
        $user->remember_token = null;
        $user->updated_by = Auth::id();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Sub-admin deleted successfully',
        ], 200);
    }

    private function present(User $user): array
    {
        return [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'username'   => $user->username,
            'is_active'  => (int) $user->is_active,
            'role'       => (int) $user->role,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
