<?php

namespace App\Http\Controllers\Api;

use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class WishlistController extends Controller
{
    // Get all wishlist items for logged-in user
    public function index()
    {
        $user = Auth::user();

        // Load the related product data
        $wishlist = Wishlist::with('product')->where('user_id', $user->id)->get();

        return response()->json($wishlist);
    }

    // Toggle item in wishlist (add/remove)
    public function toggle(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $wishlist = Wishlist::where('user_id', $user->id)
                            ->where('product_id', $request->product_id)
                            ->first();

        if ($wishlist) {
            $wishlist->delete();
            return response()->json(['message' => 'Removed from wishlist']);
        } else {
            $wishlist = Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $request->product_id,
            ]);

            // Load the product relation for frontend display
            $wishlist->load('product');

            return response()->json([
                'message' => 'Added to wishlist',
                'data' => $wishlist
            ], 201);
        }
    }

    // Remove from wishlist using DELETE request
    public function remove($productId)
    {
        $user = Auth::user();

        $wishlist = Wishlist::where('user_id', $user->id)
                            ->where('product_id', $productId)
                            ->first();

        if (!$wishlist) {
            return response()->json(['message' => 'Wishlist item not found'], 404);
        }

        $wishlist->delete();

        return response()->json(['message' => 'Removed from wishlist']);
    }
}
