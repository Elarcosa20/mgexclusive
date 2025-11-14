<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;

class ReviewController extends Controller
{
    // ✅ Fetch all reviews with user info (walay product)
    public function index()
    {
        $reviews = Review::with('user:id,first_name,last_name,organization_name,profile_image,is_organization')
            ->latest()
            ->get();

        // Format user display name
        $reviews->map(function ($review) {
            $review->user->name = $review->user->is_organization
                ? $review->user->organization_name
                : trim($review->user->first_name . ' ' . $review->user->last_name);
            $review->user->avatar = $review->user->profile_image ?? null;
            return $review;
        });

        return response()->json($reviews);
    }

    // ✅ Update admin reply & status
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'admin_reply' => 'nullable|string',
            'status'      => 'required|string|in:pending,approved,rejected',
        ]);

        $review = Review::findOrFail($id);
        $review->admin_reply = $request->admin_reply;
        $review->status = $request->status;
        $review->save();

        return response()->json([
            'message' => 'Review updated successfully',
            'review'  => $review
        ]);
    }

    // ✅ Delete a review
    public function destroy($id)
    {
        $review = Review::findOrFail($id);
        $review->delete();

        return response()->json(['message' => 'Review deleted']);
    }
}
