<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $data = $request->validate([
            'is_organization'   => 'required|boolean',
            'first_name'        => 'required_if:is_organization,false',
            'last_name'         => 'required_if:is_organization,false',
            'organization_name' => 'required_if:is_organization,true',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'required|unique:users,phone',
            'password'          => 'required|confirmed|min:6',
        ]);

        $user = User::create([
            'first_name'        => $data['first_name'] ?? null,
            'last_name'         => $data['last_name'] ?? null,
            'organization_name' => $data['organization_name'] ?? null,
            'email'             => $data['email'],
            'phone'             => $data['phone'],
            'password'          => Hash::make($data['password']),
            'is_organization'   => $data['is_organization'],
            'role'              => 'customer',
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message'     => 'Registered successfully',
            'user'        => $user,
            'token'       => $token,
            'token_type'  => 'bearer',
            'expires_in'  => config('jwt.ttl') * 60,
        ], 201);
    }

    // LOGIN
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => 'required',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['login'])
                    ->orWhere('phone', $credentials['login'])
                    ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials.'],
            ]);
        }

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message'     => 'Login successful',
            'user'        => $user,
            'token'       => $token,
            'token_type'  => 'bearer',
            'expires_in'  => config('jwt.ttl') * 60,
        ]);
    }

    // GOOGLE OAUTH
    public function redirectToGoogle()
    {
        // Stateless because we're using API + JWT
        return Socialite::driver('google')->stateless()->redirect();
    }

   public function handleGoogleCallback()
{
    try {
        $googleUser = Socialite::driver('google')->stateless()->user();

        // Kuhaa ang image URL gikan sa Google
        $googleImageUrl = $googleUser->getAvatar();

        // Download ug save locally
        $imageContents = file_get_contents($googleImageUrl);
        $imageName = 'profile_' . \Illuminate\Support\Str::random(10) . '.jpg';

        // Siguroa nga storage link na create
        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists('profile_images')) {
            \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('profile_images');
        }

        \Illuminate\Support\Facades\Storage::disk('public')->put('profile_images/' . $imageName, $imageContents);

        // Create or update user
        $user = \App\Models\User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'first_name'      => $googleUser->user['given_name'] ?? null,
                'last_name'       => $googleUser->user['family_name'] ?? null,
                'organization_name'=> null,
                'phone'           => null,
                'password'        => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
                'is_organization' => false,
                'role'            => 'customer',
                'profile_image'   => 'storage/profile_images/' . $imageName, // Path sa imong local storage
            ]
        );

        // Generate JWT token
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        // Redirect sa frontend
        return redirect("http://localhost:8080/auth/google/callback?token={$token}");
    } catch (\Exception $e) {
        \Log::error('Google login error: ' . $e->getMessage());
        return redirect("http://localhost:8080/login?error=google_login_failed");
    



            // fallback redirect
            // return redirect("http://localhost:8080/login?error=google_login_failed");
        }
    }

    // ME
    public function me()
    {
        try {
            $user = auth()->user();
            if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
            return response()->json($user);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token invalid or expired'], 401);
        }
    }

    // REFRESH
    public function refresh()
    {
        try {
            $newToken = JWTAuth::parseToken()->refresh();
            return response()->json([
                'access_token' => $newToken,
                'token_type'   => 'bearer',
                'expires_in'   => config('jwt.ttl') * 60,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token refresh failed'], 401);
        }
    }

    // LOGOUT
    public function logout()
    {
        try {
            auth()->logout();
            return response()->json(['message' => 'Successfully logged out']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Logout failed'], 500);
        }
    }
}
