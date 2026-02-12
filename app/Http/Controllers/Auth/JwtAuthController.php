<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TrainingVideo;
use App\Models\UserTrainingVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Rules\UniqueActive;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\AdditionalScratchReferral;


class JwtAuthController extends Controller
{   
    protected $messages;
    public function __construct()
    {
        $this->messages = [
            "username.required" => "Invalid UserName",
            "password.required" => "Invalid Password",
        ];
    }

    public function Register(Request $request)
    {
        // Validate (aligned with ReferralController::store)
        $validator = Validator::make($request->all(), [
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'dob'           => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $age = \Carbon\Carbon::parse($value)->age;
                        if ($age < 18) {
                            $fail('The ' . $attribute . ' must indicate that the user is at least 18 years old.');
                        }
                    }
                },
            ],
            'mobile'        => ['required', new UniqueActive('users', 'mobile', null, [])],
            'nationality'   => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'city'          => 'nullable|string|max:100',
            'district'      => 'nullable|string|max:100',
            'pin_code'      => 'nullable|string|max:20',
            'language'      => 'nullable|string|max:50',
            'username'      => 'required|string|max:100|unique:users,username',
            'password'      => 'required|min:6|confirmed'
        ], [
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();
            
            if($request->referral_code){
            $referral_code = $request->referral_code;
            }else{
            $referral_code = AdditionalScratchReferral::where('is_active', 1)
                            ->where('is_all_user', 1)
                            ->where('is_deleted', 0)->value('referral_code');

            }

            $referredBy = DB::table('users')
            ->where('referral_code', $referral_code)
            ->value('id');

            if (!$referredBy) {
            return response()->json([
            'success' => false,
            'message' => 'No referral user found. Please cross-check the referral code.'
            ], 400);
            }

            $user = new User();
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
            $user->username = $request->username;
            $user->dob = $request->dob;
            $user->mobile = $request->mobile;
            $user->nationality = $request->nationality;
            $user->state = $request->state;
            $user->city = $request->city;
            $user->district = $request->district;
            $user->pin_code = $request->pin_code;
            $user->language = $request->language;
            $user->password = Hash::make($request->password);
            $user->pwd_text = $request->password;
            $user->referred_by = $referredBy; // 
            $user->referral_code = User::generateReferralCode();
            // Use provided is_active/active when present; default to 1 when absent
            $isActiveInput = $request->has('is_active') ? $request->input('is_active') : ($request->has('active') ? $request->input('active') : 1);
            $user->is_active = (int) $isActiveInput ? 1 : 0;
            $user->created_by = $referredBy;
            $user->updated_by = $referredBy;
            $user->save();

            // Assign Day 1 training video
            $day1Video = TrainingVideo::where('day', 1)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->first();
            if ($day1Video) {
                $userTraining = new UserTrainingVideo();
                $userTraining->user_id = $user->id;
                $userTraining->training_video_id = $day1Video->id;
                $userTraining->day = 1;
                $userTraining->status = UserTrainingVideo::STATUS_ASSIGNED;
                $userTraining->assigned_at = now();
                $userTraining->created_by = $referredBy;
                $userTraining->updated_by = $referredBy;
                $userTraining->save();
            }


            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Register failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not register user',
                'code' => 'registration_failed',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required'],
            'password' => ['required', 'string'],
        ], $this->messages);

        if ($validator->fails()) {
            // Validation failed, return a JSON response with validation errors
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if email exists
        $user = User::where('username', $request->username)
            ->where('is_active',1)
            ->where('is_deleted', 0)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Username Not found',
                'code' => 'username_not_found',
            ], 400);
        }

        // Check password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'InValid Password',
                'code' => 'invalid_password',
            ], 400);
        }

        // Attempt JWT authentication
        $credentials = $request->only('username', 'password');
        $credentials['role'] = $user->role;
        $credentials['is_deleted'] = 0;

        try {
            // Update remember_token BEFORE attempting login to ensure JWT claim is correct
            $user->remember_token = Str::random(60);
            $user->save();

            // Attempt JWT authentication with the updated user
            if (!$token = JWTAuth::fromUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'unauthorized',
                ], 400);
            }

            // Fetch user data for response
            $userData = User::where('id', $user->id)
                ->select(
                    'id',
                    'username',
                    'email',
                    'role',
                    'mobile'
                )->first();

            return response()->json([
                'success' => true,
                'message' => 'logged in successfully',
                'data' => [
                    'access_token' => $token
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'code' => 'internal_server_error',
            ], 500);
        }
    }
   
   
    public function AuthUser()
    {
        $user = User::where('id', Auth::user()->id)
               ->first();
         return response()->json([
            'success' => true,
            'data' => $user,
        ], 200);
    }
   

    /**
     * Log out the currently authenticated user (invalidate the token).
     */
    public function logout()
    {
        Auth::logout();
        return response()->noContent();
    }

    /**
     * Refresh the currently authenticated user's access token.
     */
 
    public function changePassword(Request $request)
    {

       // Validate the request
        $validator = Validator::make($request->all(), [
            "current_password" => 'required',
            'new_password' => 'required|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        // Get the currently authenticated user
        $user = User::find(auth()->user()->id);

        // Check if the current password is correct
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect', 'status' => 400], 400);
        }

        // Update the user's password
        $user->password = Hash::make($request->new_password);
        $user->pwd_text = $request->new_password;
        $user->save();


        return response()->json(['message' => 'Password successfully changed.','status' => 200], 200);
    }
    public function updatePersonalDetails(Request $request)
    {
        $user = User::find(auth()->user()->id);

        // Validate incoming fields (all optional), enforce uniqueness on username/mobile when provided
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'dob' => [
                'sometimes',
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $age = \Carbon\Carbon::parse($value)->age;
                        if ($age < 18) {
                            $fail('The ' . $attribute . ' must indicate that the user is at least 18 years old.');
                        }
                    }
                },
            ],
            'mobile' => 'sometimes|required|string|max:15|unique:users,mobile,' . $user->id,
            'nationality' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'city' => 'sometimes|nullable|string|max:100',
            'district' => 'sometimes|nullable|string|max:100',
            'pin_code' => 'sometimes|nullable|string|max:20',
            'language' => 'sometimes|nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // Assign only provided keys (allow nulls when explicitly sent)
        foreach ([
            'first_name', 'last_name', 'dob', 'mobile', 'nationality',
            'state', 'city', 'district', 'pin_code', 'language'
        ] as $field) {
            if ($request->has($field)) {
                $user->{$field} = $request->input($field);
            }
        }
        if($user->role == 2){
         $user->is_profile_updated = 1;   
        }

        $user->save();

        return response()->json([
            'message' => 'Personal details updated successfully.',
            'user' => $user,
            'status' => 200,
        ], 200);
    }
    public function DeleteAccount(Request $request)
    {
        $u = User::find(auth()->user()->id);
        $u->is_deleted = 1;
        $u->save();
        return response()->json([
            'message' => 'Data Deleted successfully.',
            'status' => 200,
        ], 200);
    }

   
}
