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


class JwtAuthController extends Controller
{
    public function register(Request $request)
    {
        // mobile length and unique validation pending
        $validateUser = Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'username' => 'required|unique:users,username|min:8',
                'mobile' => 'required|unique:users,mobile',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|confirmed|min:8',
            ]
        );

        if ($validateUser->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'validation error',
                'errors' => $validateUser->errors()
            ], 200);
        }

        $newUser =   User::create([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'username' => $request->username,
            'password' => Hash::make($request->password)
        ]);
        $newUser->pwd_text = $request->password;
        $newUser->referral_code = 'STARTUP' . 1000 + $newUser->id;
        $newUser->save();

        return response()->json([
            'status' => 200
        ], 200);
    }


    /**
     * Authenticate a user and return an access token.
     */
    public function login(Request $request)
    {
        // Validate the request
        $request->validate([
            'identifier' => ['required'],
            'password' => ['required', 'string'],
        ]);

        // Get the input credentials
        $identifier = $request->input('identifier');
        $password = $request->input('password');

        // Find the user by mobile or email
        $user = User::where(function ($query) use ($identifier) {
            $query->where('mobile', $identifier)
                ->orWhere('email', $identifier)
                ->orWhere('username', $identifier);
        })
            ->where('pwd_text', $password)
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->first();

        // Check if the user exists and is not verified
        if ($user) {
    
            if (
                Auth::attempt(['mobile' => $identifier, 'password' => $password]) ||
                Auth::attempt(['email' => $identifier, 'password' => $password])||
                Auth::attempt(['username' => $identifier, 'password' => $password])
            ) {
                User::where('id', auth()->user()->id)->update(['last_login' => now()]);
                $user = User::where('id', auth()->user()->id)
                    ->select(
                        'id',
                        'username',
                        'name',
                        'email',
                        'referral_code',
                        'mobile',
                        'role'
                    )->first();
                $token = $user->createToken("api-token")->plainTextToken;

                return response()->json([
                    'user' => $user,
                    'access_token' => $token,
                ]);
            }
        }

        // Return unauthorized response if the user is not authenticated
        return response()->json(['error' => 'Invalid credentials'], 401);
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
