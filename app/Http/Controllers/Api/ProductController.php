<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Wishlist;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct() {
        $this->middleware('jwt.auth')->except(['index', 'show']);
        $this->middleware(function($request, $next){
            if(auth()->check() && auth()->user()->role !== 'admin') {
                return response()->json(['message'=>'Unauthorized'], 403);
            }
            return $next($request);
        })->except(['index','show', 'deactivateProduct']);
    }

    // Public listing of active products with enhanced search
    public function index(Request $request) {
        try {
            $query = Product::query()->where('status', 'active');

            // Eager load relationships based on include parameter
            if ($request->has('include')) {
                $includes = explode(',', $request->include);
                if (in_array('category', $includes)) {
                    $query->with('category');
                }
                if (in_array('images', $includes)) {
                    // Images are stored as JSON in the database, no need for relationship
                }
            } else {
                // Default eager loading
                $query->with('category');
            }

            // Enhanced search functionality
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhere('color', 'like', "%{$search}%")
                      ->orWhere('note', 'like', "%{$search}%")
                      ->orWhere('features', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Category filter
            if ($categoryId = $request->query('category')) {
                $query->where('category_id', $categoryId);
            }

            // Limit results
            if ($limit = $request->query('limit')) {
                $query->limit($limit);
            }

            $products = $query->get();

            // Format response to include images properly
            $formattedProducts = $products->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'prices' => $product->prices,
                    'available_sizes' => $product->available_sizes,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'category' => $product->category,
                    'status' => $product->status,
                    'material' => $product->material,
                    'color' => $product->color,
                    'note' => $product->note,
                    'dimensions' => $product->dimensions,
                    'weight' => $product->weight,
                    'compartments' => $product->compartments,
                    'features' => $product->features,
                    'image' => $product->image,
                    'images' => $product->images,
                    'stock_quantity' => $product->stock_quantity,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at
                ];
            });

            return response()->json($formattedProducts, 200);

        } catch (\Exception $e) {
            \Log::error('Products API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    // Admin listing (all products) with enhanced search
    public function adminIndex(Request $request) {
        try {
            $query = Product::with('category');

            // Enhanced search functionality for admin
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhere('color', 'like', "%{$search}%")
                      ->orWhere('note', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter for admin
            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }

            $products = $query->get();
            return response()->json($products, 200);

        } catch (\Exception $e) {
            \Log::error('Admin Products API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function show($id) {
        try {
            $product = Product::with('category')->findOrFail($id);
            return response()->json($product, 200);
        } catch (\Exception $e) {
            \Log::error('Product Show API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Product not found'], 404);
        }
    }

    // Create product
    public function store(Request $request) {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
            'prices' => 'nullable|array',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'main_image' => 'nullable|file|image|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'nullable|file|image|max:2048',
            'status' => 'required|in:active,inactive',
            'material' => 'nullable|string',
            'note' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'weight' => 'nullable|string',
            'compartments' => 'nullable|string',
            'features' => 'nullable|array',
            'sizes' => 'nullable|array'
        ]);

        $category = \App\Models\Category::findOrFail($request->category_id);

        // Handle main image
        $imageUrl = $request->hasFile('main_image') 
            ? asset('storage/' . $request->file('main_image')->store('products', 'public')) 
            : null;

        // Handle gallery images
        $imagesUrls = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $imagesUrls[] = asset('storage/' . $file->store('products', 'public'));
            }
        }

        // Pricing logic based on category type
        if (strtolower($category->type) === 'apparel') {
            $sizes = $request->sizes ?? [];
            $prices = $request->prices ?? [];
            $price = null;
        } else {
            $sizes = null;
            $prices = null;
            $price = $request->price ?? 0;
        }

        $product = Product::create([
            'name' => $request->name,
            'price' => $price,
            'prices' => $prices,
            'available_sizes' => $sizes,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'status' => $request->status,
            'material' => $request->material,
            'color' => $request->color,
            'note' => $request->note,
            'dimensions' => $request->dimensions,
            'weight' => $request->weight,
            'compartments' => $request->compartments,
            'features' => $request->features,
            'image' => $imageUrl,
            'images' => $imagesUrls
        ]);

        $product->load('category');
        return response()->json($product, 201);
    }

    // Update product with automatic cleanup when status changes to inactive
    public function update(Request $request, $id) {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
            'prices' => 'nullable|array',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'main_image' => 'nullable|file|image|max:2048',
            'main_image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|file|image|max:2048',
            'retain_images' => 'nullable|array',
            'retain_images.*' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'material' => 'nullable|string',
            'note' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'weight' => 'nullable|string',
            'compartments' => 'nullable|string',
            'features' => 'nullable|array',
            'sizes' => 'nullable|array'
        ]);

        $product = Product::findOrFail($id);
        $category = \App\Models\Category::findOrFail($request->category_id);

        // Store old status to check if it changed to inactive
        $oldStatus = $product->status;
        $newStatus = $request->status;

        // Main image handling
        if ($request->hasFile('main_image')) {
            if ($product->image) {
                $oldPath = str_replace(asset('storage/'), '', $product->image);
                Storage::disk('public')->delete($oldPath);
            }
            $product->image = asset('storage/' . $request->file('main_image')->store('products', 'public'));
        } elseif ($request->filled('main_image_url')) {
            $product->image = $request->main_image_url;
        }

        // Gallery images handling
        $existingImages = is_array($product->images) ? $product->images : ($product->images ? (array)$product->images : []);
        $retain = $request->input('retain_images', []);
        $toDelete = array_values(array_diff($existingImages, $retain));
        foreach ($toDelete as $oldUrl) {
            if ($oldUrl) {
                $oldPath = str_replace(asset('storage/'), '', $oldUrl);
                Storage::disk('public')->delete($oldPath);
            }
        }

        $newImagesList = array_values($retain);
        $newUploads = $request->hasFile('images') ? $request->file('images') : [];
        $allowedRemaining = 5 - count($newImagesList);
        if (count($newUploads) > $allowedRemaining) {
            return response()->json([
                'message' => "You can only have up to 5 images. You tried to upload " . count($newUploads) . " files but only {$allowedRemaining} slots are available."
            ], 422);
        }
        foreach ($newUploads as $file) {
            $newImagesList[] = asset('storage/' . $file->store('products', 'public'));
        }
        $product->images = $newImagesList;

        // Pricing logic based on category type
        if (strtolower($category->type) === 'apparel') {
            $sizes = $request->sizes ?? [];
            $prices = $request->prices ?? [];
            $price = null;
        } else {
            $sizes = null;
            $prices = null;
            $price = $request->price ?? 0;
        }

        $product->update([
            'name' => $request->name,
            'price' => $price,
            'prices' => $prices,
            'available_sizes' => $sizes,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'status' => $newStatus,
            'material' => $request->material,
            'note' => $request->note,
            'dimensions' => $request->dimensions,
            'weight' => $request->weight,
            'compartments' => $request->compartments,
            'features' => $request->features,
        ]);

        // If status changed from active to inactive, remove from user collections
        if ($oldStatus === 'active' && $newStatus === 'inactive') {
            $this->removeProductFromUserCollections($product->id);
        }

        $product->load('category');
        return response()->json($product, 200);
    }

    // Remove product from all user wishlists and carts when deactivated
    public function deactivateProduct(Product $product)
    {
        try {
            DB::transaction(function () use ($product) {
                // Remove from all wishlists
                Wishlist::where('product_id', $product->id)->delete();
                
                // Remove from all carts
                Cart::where('product_id', $product->id)->delete();
                
                // Log the cleanup
                \Log::info("Product {$product->id} removed from all user collections due to deactivation");
            });
            
            return response()->json([
                'message' => 'Product removed from all user collections successfully',
                'removed_from_wishlists' => true,
                'removed_from_carts' => true
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Failed to remove product {$product->id} from collections: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to remove product from collections',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Internal method to remove product from user collections
    private function removeProductFromUserCollections($productId)
    {
        try {
            \Log::info("🔄 Removing product {$productId} from user collections...");
            
            // Remove from all wishlists
            $wishlistCount = Wishlist::where('product_id', $productId)->delete();
            
            // Remove from all carts
            $cartCount = Cart::where('product_id', $productId)->delete();
            
            \Log::info("✅ Product {$productId} removed from {$wishlistCount} wishlists and {$cartCount} carts");
            
            return [
                'wishlist_removals' => $wishlistCount,
                'cart_removals' => $cartCount
            ];
            
        } catch (\Exception $e) {
            \Log::error("❌ Failed to remove product {$productId} from collections: " . $e->getMessage());
            throw $e;
        }
    }

    // Enhanced product deletion with cleanup
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            
            // Remove from user collections first
            $this->removeProductFromUserCollections($product->id);
            
            // Delete images from storage
            if ($product->image) {
                $oldPath = str_replace(asset('storage/'), '', $product->image);
                Storage::disk('public')->delete($oldPath);
            }
            
            if ($product->images && is_array($product->images)) {
                foreach ($product->images as $imageUrl) {
                    if ($imageUrl) {
                        $oldPath = str_replace(asset('storage/'), '', $imageUrl);
                        Storage::disk('public')->delete($oldPath);
                    }
                }
            }
            
            // Delete the product
            $product->delete();
            
            return response()->json([
                'message' => 'Product deleted successfully',
                'removed_from_collections' => true
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Product Delete API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete product'], 500);
        }
    }

    // Get products with status filter for admin
    public function getProductsByStatus(Request $request, $status)
    {
        try {
            if (!in_array($status, ['active', 'inactive'])) {
                return response()->json(['error' => 'Invalid status'], 400);
            }
            
            $query = Product::with('category')->where('status', $status);
            
            // Search functionality
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }
            
            $products = $query->get();
            return response()->json($products, 200);
            
        } catch (\Exception $e) {
            \Log::error('Products by Status API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    // Bulk status update with automatic cleanup
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'status' => 'required|in:active,inactive'
        ]);
        
        try {
            $productIds = $request->product_ids;
            $newStatus = $request->status;
            
            DB::transaction(function () use ($productIds, $newStatus) {
                // Get current status of products
                $products = Product::whereIn('id', $productIds)->get();
                
                // Update status
                Product::whereIn('id', $productIds)->update(['status' => $newStatus]);
                
                // If setting to inactive, remove from collections
                if ($newStatus === 'inactive') {
                    foreach ($productIds as $productId) {
                        $this->removeProductFromUserCollections($productId);
                    }
                }
            });
            
            return response()->json([
                'message' => 'Products status updated successfully',
                'updated_count' => count($productIds),
                'status' => $newStatus
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Bulk Status Update Error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update products status'], 500);
        }
    }
}