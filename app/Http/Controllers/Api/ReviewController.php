<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    // ✅ Public: get all reviews with user info
    public function index()
    {
        try {
            $reviews = Review::with('user:id,first_name,last_name,organization_name,profile_image,is_organization')
                ->latest()
                ->get();

            // Format the response
            $formattedReviews = $reviews->map(function ($review) {
                $userName = $review->user->is_organization
                    ? $review->user->organization_name
                    : trim($review->user->first_name . ' ' . $review->user->last_name);
                
                // Fix profile image URL - remove any duplicate /storage/
                $profileImage = null;
                if ($review->user->profile_image) {
                    $profileImage = $this->getCorrectImageUrl($review->user->profile_image);
                }

                // Fix review images URLs
                $reviewImages = [];
                if ($review->images && is_array($review->images)) {
                    foreach ($review->images as $image) {
                        $reviewImages[] = $this->getCorrectImageUrl($image);
                    }
                }

                return [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'images' => $reviewImages,
                    'admin_reply' => $review->admin_reply,
                    'created_at' => $review->created_at,
                    'user' => [
                        'id' => $review->user->id,
                        'name' => $userName,
                        'profile_image' => $profileImage,
                        'is_organization' => $review->user->is_organization
                    ]
                ];
            });

            return response()->json($formattedReviews, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch reviews: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper method to get correct image URL
    private function getCorrectImageUrl($imagePath)
    {
        // Remove any duplicate /storage/ from the path
        $cleanPath = str_replace('/storage/', '', $imagePath);
        $cleanPath = ltrim($cleanPath, '/');
        
        // Check if file exists in storage
        if (Storage::disk('public')->exists($cleanPath)) {
            return Storage::url($cleanPath);
        }
        
        // If not found, try the original path
        if (Storage::disk('public')->exists($imagePath)) {
            return Storage::url($imagePath);
        }
        
        return null;
    }

    // ✅ Authenticated: submit review
    public function store(Request $request)
    {
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
            'images'  => 'nullable|array|max:3',
            'images.*'=> 'image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('reviews', 'public');
                    // Store just the path, not the full URL
                    $imagePaths[] = $path;
                }
            }

            $review = Review::create([
                'user_id' => $user->id,
                'rating'  => $request->rating,
                'comment' => $request->comment,
                'images'  => $imagePaths,
            ]);

            // Format the response with correct URLs
            $review->load('user:id,first_name,last_name,organization_name,profile_image,is_organization');
            
            $userName = $review->user->is_organization
                ? $review->user->organization_name
                : trim($review->user->first_name . ' ' . $review->user->last_name);
            
            $profileImage = $review->user->profile_image 
                ? $this->getCorrectImageUrl($review->user->profile_image)
                : null;

            $reviewImages = [];
            if ($review->images && is_array($review->images)) {
                foreach ($review->images as $image) {
                    $reviewImages[] = $this->getCorrectImageUrl($image);
                }
            }

            $formattedReview = [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $reviewImages,
                'admin_reply' => $review->admin_reply,
                'created_at' => $review->created_at,
                'user' => [
                    'id' => $review->user->id,
                    'name' => $userName,
                    'profile_image' => $profileImage,
                    'is_organization' => $review->user->is_organization
                ]
            ];

            return response()->json($formattedReview, 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to submit review: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ Authenticated: update review
    public function update(Request $request, $id)
    {
        $request->validate([
            'rating'  => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|string|max:1000',
            'images'  => 'nullable|array|max:3',
            'images.*'=> 'sometimes|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $review = Review::where('id', $id)->where('user_id', $user->id)->first();

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        try {
            // Update basic fields
            if ($request->has('rating')) {
                $review->rating = $request->rating;
            }
            if ($request->has('comment')) {
                $review->comment = $request->comment;
            }

            // Handle image updates if provided
            if ($request->hasFile('images')) {
                $imagePaths = [];
                foreach ($request->file('images') as $image) {
                    $path = $image->store('reviews', 'public');
                    $imagePaths[] = $path;
                }
                $review->images = $imagePaths;
            }

            $review->save();

            // Format response
            $review->load('user:id,first_name,last_name,organization_name,profile_image,is_organization');
            
            $userName = $review->user->is_organization
                ? $review->user->organization_name
                : trim($review->user->first_name . ' ' . $review->user->last_name);
            
            $profileImage = $review->user->profile_image 
                ? $this->getCorrectImageUrl($review->user->profile_image)
                : null;

            $reviewImages = [];
            if ($review->images && is_array($review->images)) {
                foreach ($review->images as $image) {
                    $reviewImages[] = $this->getCorrectImageUrl($image);
                }
            }

            $formattedReview = [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $reviewImages,
                'admin_reply' => $review->admin_reply,
                'created_at' => $review->created_at,
                'user' => [
                    'id' => $review->user->id,
                    'name' => $userName,
                    'profile_image' => $profileImage,
                    'is_organization' => $review->user->is_organization
                ]
            ];

            return response()->json($formattedReview, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update review: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ Authenticated: soft delete review
    public function destroy($id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $review = Review::where('id', $id)->where('user_id', $user->id)->first();

        if (!$review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->delete(); // ✅ soft delete

        return response()->json(['message' => 'Review deleted successfully.'], 200);
    }
}