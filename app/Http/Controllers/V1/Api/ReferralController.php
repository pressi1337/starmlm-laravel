<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingVideo;
use Illuminate\Http\Request;


use App\Models\User;
use App\Models\UserTrainingVideo;
use Illuminate\Support\Facades\Validator;
use App\Rules\UniqueActive;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ReferralController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    protected $messages;
    protected array $sortable = ['created_at', 'username', 'first_name', 'last_name', 'mobile', 'id'];
    protected array $filterable = ['username', 'first_name', 'last_name', 'mobile', 'is_active', 'id'];
    public function __construct()
    {
        $this->messages = [
            "first_name.required" => "First Name Required",
            "last_name.required" => "Last Name Required",
            "dob.required" => "DOB Required",
            "mobile.required" => "Mobile Required",
            "nationality.required" => "Nationality Required",
            "state.required" => "State Required",
            "city.required" => "City Required",
            "district.required" => "District Required",
            "pin_code.required" => "Pin Code Required",
            "language.required" => "Language Required",
            "username.required" => "Username Required",
            "password.required" => "Password Required",
            "mobile.unique" => "Mobile Already Exists",
            "username.unique" => "Username Already Exists",
            "password.confirmed" => "Password Confirmation Mismatch",
            "password.min" => "Password Must Be At Least 6 Characters Long",
            "mobile.max" => "Mobile Must Be At Most 15 Characters Long",
            "mobile.string" => "Mobile Must Be A String",
            "mobile.required" => "Mobile Required",
            "nationality.max" => "Nationality Must Be At Most 100 Characters Long",
            "nationality.string" => "Nationality Must Be A String",
            "state.max" => "State Must Be At Most 100 Characters Long",
            "state.string" => "State Must Be A String",
            "city.max" => "City Must Be At Most 100 Characters Long",
            "city.string" => "City Must Be A String",
            "district.max" => "District Must Be At Most 100 Characters Long",
            "district.string" => "District Must Be A String",
            "pin_code.max" => "Pin Code Must Be At Most 20 Characters Long",
            "pin_code.string" => "Pin Code Must Be A String",
            "language.max" => "Language Must Be At Most 50 Characters Long",
            "language.string" => "Language Must Be A String",
            "username.max" => "Username Must Be At Most 100 Characters Long",
            "username.string" => "Username Must Be A String",
            "password.max" => "Password Must Be At Most 100 Characters Long",
            "password.string" => "Password Must Be A String",

        ];
    }
    public function index(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination parameters
            $page_size = max(0, (int) $request->query('page_size', 10)); // 0 disables pagination
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            // Parse search_param JSON
            $search_param_raw = $request->query('search_param', '{}');
            $search_param = [];
            try {
                $decoded = json_decode($search_param_raw, true);
                if (is_array($decoded)) {
                    $search_param = $decoded;
                }
            } catch (\Throwable $e) {
                $search_param = [];
            }

            // Start building the query
            $query = User::query();

            // Apply default filters
            $query->where('is_deleted', 0)->where('referred_by', Auth::id());

            // Apply search_param filters (whitelisted)
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, $this->filterable, true)) {
                    $query->where($key, $value);
                }
            }

            // Apply search filter across common fields
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('username', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('first_name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('last_name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('mobile', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Get total records for pagination
            $total_records = $query->count();

            // Apply sorting and pagination
            $users = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)
                        ->take($page_size);
                })
                ->get()
                ->map(function ($user) {
                    $user->created_at_formatted = $user->created_at
                        ? $user->created_at->format('d-m-Y h:i A')
                        : '-';
                    $user->updated_at_formatted = $user->updated_at
                        ? $user->updated_at->format('d-m-Y h:i A')
                        : '-';
                    return $user;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $users,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Referral index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    public function allReferral(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Default sorting
            $sort_column = $request->query('sort_column', 'created_at');
            $sort_direction = strtoupper($request->query('sort_direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'created_at';
            }

            // Pagination parameters
            $page_size = max(0, (int) $request->query('page_size', 10)); // 0 disables pagination
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));

            // Parse search_param JSON
            $search_param_raw = $request->query('search_param', '{}');
            $search_param = [];
            try {
                $decoded = json_decode($search_param_raw, true);
                if (is_array($decoded)) {
                    $search_param = $decoded;
                }
            } catch (\Throwable $e) {
                $search_param = [];
            }

            // Start building the query
            $query = User::query();

            // Apply default filters
            $query->where('is_deleted', 0);

            // Apply search_param filters (whitelisted)
            foreach (($search_param ?? []) as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                if (in_array($key, $this->filterable, true)) {
                    $query->where($key, $value);
                }
            }

            // Apply search filter across common fields
            if ($search_term !== '') {
                $query->where(function ($q) use ($search_term) {
                    $q->where('username', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('first_name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('last_name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('name', 'LIKE', '%' . $search_term . '%')
                        ->orWhere('mobile', 'LIKE', '%' . $search_term . '%');
                });
            }

            // Get total records for pagination
            $total_records = $query->count();

            // Apply sorting and pagination
            $users = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)
                        ->take($page_size);
                })
                ->get()
                ->map(function ($user) {
                    $user->created_at_formatted = $user->created_at
                        ? $user->created_at->format('d-m-Y h:i A')
                        : '-';
                    $user->updated_at_formatted = $user->updated_at
                        ? $user->updated_at->format('d-m-Y h:i A')
                        : '-';
                    return $user;
                });

            // Build the response
            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $users,
                'pageInfo' => [
                    'page_size' => $page_size,
                    'page_number' => $page_number,
                    'total_pages' => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Referral index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // API context: return a sample payload template for Postman
        return response()->json([
            'success' => true,
            'message' => 'Sample payload template',
            'data' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'dob' => '1990-01-01',
                'mobile' => '+911234567890',
                'nationality' => 'Indian',
                'state' => 'State name',
                'city' => 'City name',
                'district' => 'District name',
                'pin_code' => '560001',
                'language' => 'English',
                'username' => 'john_doe_90',
                'password' => 'Secret123',
                'password_confirmation' => 'Secret123',
                'is_active' => true
            ]
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'first_name'    => 'required|string|max:100',
                'last_name'     => 'required|string|max:100',
                'dob'           => 'nullable|date',
                'mobile'        => ['required', new UniqueActive('users', 'mobile', null, [])],
                'nationality'   => 'nullable|string|max:100',
                'state'         => 'nullable|string|max:100',
                'city'          => 'nullable|string|max:100',
                'district'      => 'nullable|string|max:100',
                'pin_code'      => 'nullable|string|max:20',
                'language'      => 'nullable|string|max:50',
                'username'      => 'required|string|max:100|unique:users,username',
                'password'      => 'required|min:6|confirmed',


            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();
            $w = new User();
            $w->first_name = $request->first_name;
            $w->last_name = $request->last_name;
            $w->username = $request->username;
            $w->dob = $request->dob;
            $w->mobile = $request->mobile;
            $w->nationality = $request->nationality;
            $w->state = $request->state;
            $w->city = $request->city;
            $w->district = $request->district;
            $w->pin_code = $request->pin_code;
            $w->language = $request->language;
            $w->password = Hash::make($request->password);
            $w->pwd_text = $request->password;
            $w->referred_by = $auth_user_id;
            $w->referral_code = User::generateReferralCode();
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $w->is_active = (int) $isActiveInput ? 1 : 0;
            $w->created_by =  $auth_user_id;
            $w->updated_by =  $auth_user_id;
            $w->save();
            // assign Day 1
            $day1Video = TrainingVideo::where('day', 1)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->first();
            if ($day1Video) {
                $user_training = new UserTrainingVideo();
                $user_training->user_id = $w->id;
                $user_training->training_video_id = $day1Video->id;
                $user_training->day = 1;
                $user_training->status = UserTrainingVideo::STATUS_ASSIGNED;
                $user_training->assigned_at = now();
                $user_training->created_by = $auth_user_id;
                $user_training->updated_by = $auth_user_id;
                $user_training->save();
            }

            DB::commit();

            return response()->json(['message' => 'Created successfully', 'status' => 200]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Referral store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $referral = User::where('id', $id)
                ->where('is_deleted', 0)
                ->where('referred_by', Auth::id())
                ->first();

            if (!$referral) {
                return response()->json(['success' => false, 'message' => 'Not found'], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $referral
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Referral show failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $referral = User::where('id', $id)
                ->where('is_deleted', 0)
                ->where('referred_by', Auth::id())
                ->first();

            if (!$referral) {
                return response()->json(['success' => false, 'message' => 'Not found'], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Success',
                'data' => $referral,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Referral edit failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Find the referral user
            $referral = User::where('id', $id)
                ->where('is_deleted', 0)
                ->where('referred_by', Auth::id())
                ->first();

            if (!$referral) {
                return response()->json(['success' => false, 'message' => 'Not found'], 400);
            }

            // Validation rules - similar to store but adjusted for update
            $validator = Validator::make($request->all(), [
                'first_name'    => 'required|string|max:100',
                'last_name'     => 'required|string|max:100',
                'dob'           => 'nullable|date',
                'mobile'        => ['required', new UniqueActive('users', 'mobile', $id, [])],
                'nationality'   => 'nullable|string|max:100',
                'state'         => 'nullable|string|max:100',
                'city'          => 'nullable|string|max:100',
                'district'      => 'nullable|string|max:100',
                'pin_code'      => 'nullable|string|max:20',
                'language'      => 'nullable|string|max:50',
                'is_active'     => 'nullable|boolean',
            ], $this->messages);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $auth_user_id = Auth::id();

            // Update user fields
            $referral->first_name = $request->first_name;
            $referral->last_name = $request->last_name;
            $referral->dob = $request->dob;
            $referral->mobile = $request->mobile;
            $referral->nationality = $request->nationality;
            $referral->state = $request->state;
            $referral->city = $request->city;
            $referral->district = $request->district;
            $referral->pin_code = $request->pin_code;
            $referral->language = $request->language;
            $referral->updated_by = $auth_user_id;


            // Handle is_active field
            if ($request->has('is_active')) {
                $referral->is_active = (int) $request->is_active ? 1 : 0;
            }

            $referral->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Updated successfully'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Referral update failed', [
                'error' => $e->getMessage(),
                'user_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {}
}
