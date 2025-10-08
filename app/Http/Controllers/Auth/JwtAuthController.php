<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;


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
         // Validate
         $validator = Validator::make(
            $request->all(),
            [
                'first_name' => 'required',
                'username' => 'required|unique:users,username|min:8',
                'mobile' => ['required', 'string', 'max:15', 'unique:users,mobile'],
                'email' => 'unique:users,email',
                'password' => 'required|confirmed|min:8',
            ]
        );

        if ($validator->fails()) {
            $errors = $validator->errors();
            if ($errors->has('username')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is invalid or already taken',
                    'code' => 'invalid_username',
                    'error' => [
                        'username' => 'Username is invalid or already taken'
                    ]
                ], 400);
            }
            if ($errors->has('email')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is invalid or already taken',
                    'code' => 'invalid_email',
                    'error' => [
                        'email' => 'Email is invalid or already taken'
                    ]
                ], 400);
            }
            if ($errors->has('mobile')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mobile number is invalid or already taken',
                    'code' => 'invalid_mobile',
                    'error' => [
                        'mobile' => 'Mobile number is invalid or already taken'
                    ]
                ], 400);
            }
            if ($errors->has('password')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password must be at least 6 characters',
                    'code' => 'invalid_password',
                    'error' => [
                        'password' => 'Password must be at least 6 characters'
                    ]
                ], 400);
            }
        }

        try {
            // Create the user

            $user =   User::create([
                'first_name' => $request->first_name,
                'email' => $request->email,
                'mobile' => $request->mobile,
                'username' => $request->username,
                'password' => Hash::make($request->password)
            ]);
            $user->pwd_text = $request->password;
            $user->referral_code = 'STARTUP' . 1000 + $user->id;
            $user->save();

            // Generate JWT token
            $token = JWTAuth::fromUser($user);

            // Fetch user data
            $userData = User::where('id', $user->id)
                ->select(
                    'id',
                    'username',
                    'email',
                    'mobile',
                    'role'
                )->first();

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'access_token' => $token
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not register user',
                'code' => 'registration_failed',
                'error' => [
                    'general' => 'Could not register user'
                ]
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
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'unauthorized',
                ], 400);
            }

            // Fetch user data
            $user = User::where('id', $user->id)
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
            'dob' => 'sometimes|nullable|date',
            'mobile' => 'sometimes|required|string|min:8|max:15|unique:users,mobile,' . $user->id,
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
