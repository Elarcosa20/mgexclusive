<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Show current authenticated user's profile
     */
    public function show()
    {
        $user = Auth::user();

        // Attach full URL for profile image
        if ($user->profile_image) {
            $user->profile_image_url = Storage::url($user->profile_image);
        } else {
            $user->profile_image_url = null;
        }

        return response()->json($user);
    }

    /**
     * Update profile information (PUT)
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        // Common validation
        $rules = [
            'phone' => ['required', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ];

        if (!$user->is_organization) {
            // Individual
            $rules['first_name'] = 'required|string|max:255';
            $rules['last_name'] = 'required|string|max:255';
            $rules['dob'] = 'required|date';
        } else {
            // Organization
            $rules['organization_name'] = 'required|string|max:255';
            $rules['date_founded'] = 'required|date';
        }

        $validated = $request->validate($rules);

        // Update fields
        if (!$user->is_organization) {
            $user->first_name = $validated['first_name'];
            $user->last_name = $validated['last_name'];
            $user->dob = $validated['dob'];
        } else {
            $user->organization_name = $validated['organization_name'];
            $user->date_founded = $validated['date_founded'];
        }

        $user->phone = $validated['phone'];
        $user->email = $validated['email'];
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * Update profile image (PATCH)
     */
    public function updateProfileImage(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'profile_image' => 'required|image|max:2048', // max 2MB
        ]);

        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $path = $request->file('profile_image')->store('profile_images', 'public');
            $user->profile_image = $path;
            $user->save();
        }

        return response()->json([
            'message' => 'Profile image updated successfully',
            'profile_image_url' => Storage::url($user->profile_image),
        ]);
    }
}
