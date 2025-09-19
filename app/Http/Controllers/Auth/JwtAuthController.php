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
                'name' => 'required',
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
                'name' => $request->name,
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
            ->select(
                'id',
                'username',
                'name',
                'email',
                'referral_code',
                'mobile',
                'role'
            )->first();
        return response()->json([
            'user' => $user,

        ]);
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
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        // Get the currently authenticated user
        $user = User::find(auth()->user()->id);

        // Check if the current password is correct
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 400);
        }

        // Update the user's password
        $user->password = Hash::make($request->new_password);
        $user->pwd_text = $request->new_password;
        $user->save();


        return response()->json(['message' => 'Password successfully changed.'], 200);
    }
    public function updatePersonalDetails(Request $request)
    {
        $user = user::find(auth()->user()->id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email,' . $user->id,
            ],
            'mobile' => [
                'required',
                'string',
                'min:8',
                'max:12',
                'unique:users,mobile,' . $user->id,
            ],
            // 'profile' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Adjust for file upload
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->mobile = $request->input('mobile');

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
        return response()->json(['status' => 200]);
    }

   
}
