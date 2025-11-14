<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class ManagePasswordController extends Controller
{
    /**
     * Change the authenticated user's password
     */
    public function changePassword(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'current_password'          => 'required',
            'new_password'              => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        // Invalidate current token for security
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Password updated successfully. Please log in again.'
        ]);
    }
}
