<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Models\UserVoucher;
use App\Models\Cart;
use App\Models\Product;
use App\Models\CustomProposal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Place a new order - ENHANCED to properly handle both regular products and custom proposals
     */
    public function store(Request $request)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer', // Made nullable for custom proposals
            'items.*.custom_proposal_id' => 'nullable|integer|exists:custom_proposals,id', // Added for custom proposals
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.size' => 'nullable|string|max:50',
            'items.*.price' => 'nullable|numeric',
            'items.*.size_price' => 'nullable|numeric',
            'items.*.is_customized' => 'boolean', // Added to distinguish between regular and custom items
            'user_voucher_id' => 'nullable|integer|exists:user_voucher,id',
            'shipping_fee' => 'nullable|numeric',
            'payment_method' => 'nullable|string|in:cod,gcash,credit-card',
            'customer_first_name' => 'required|string',
            'customer_last_name' => 'required|string',
            'customer_email' => 'required|email',
            'customer_phone' => 'required|string',
            'customer_address' => 'required|string',
        ]);

        $cartItems = $request->items;
        $userVoucherId = $request->user_voucher_id;
        $shippingFee = $request->shipping_fee ?? 0;
        $paymentMethod = $request->payment_method ?? 'cod';

        $customer = [
            'first_name' => $request->customer_first_name,
            'last_name'  => $request->customer_last_name,
            'email'      => $request->customer_email,
            'phone'      => $request->customer_phone,
            'address'    => $request->customer_address,
        ];

        DB::beginTransaction();

        try {
            $subtotal = 0;

            $order = Order::create([
                'user_id' => $user->id,
                'subtotal' => 0,
                'shipping_fee' => $shippingFee,
                'total_amount' => 0,
                'voucher_id' => null,
                'voucher_code' => null,
                'discount_amount' => 0,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'status' => 'pending',
                'customer' => json_encode($customer),
            ]);

            // Get current cart items count for logging
            $initialCartCount = Cart::where('user_id', $user->id)->count();
            Log::info("User {$user->id} placing order with {$initialCartCount} cart items");

            foreach ($cartItems as $item) {
                $isCustomized = isset($item['is_customized']) ? $item['is_customized'] : false;
                
                if ($isCustomized && isset($item['custom_proposal_id'])) {
                    // Handle custom proposal item (both apparel and accessories)
                    $this->handleCustomProposalOrderItem($order, $item, $subtotal);
                } else if (isset($item['product_id'])) {
                    // Handle regular product item
                    $this->handleRegularProductOrderItem($order, $item, $subtotal);
                } else {
                    Log::warning("Invalid order item: " . json_encode($item));
                    continue;
                }
            }

            // ✅ Apply specific voucher instance if available
            $voucherDiscount = 0;
            $voucherId = null;
            $voucherCode = null;
            
            if ($userVoucherId) {
                $userVoucher = UserVoucher::with('voucher')
                    ->where('id', $userVoucherId)
                    ->where('user_id', $user->id)
                    ->first();

                if ($userVoucher) {
                    // Check if this specific voucher instance is valid
                    if (!$userVoucher->isUsed() && !$userVoucher->isExpired()) {
                        $voucher = $userVoucher->voucher;
                        
                        // Check if voucher template is active
                        if ($voucher && $voucher->status === 'enabled') {
                            $voucherDiscount = round(($subtotal * $voucher->percent) / 100, 2);
                            $voucherId = $voucher->id;
                            $voucherCode = $userVoucher->voucher_code;

                            // ✅ Mark THIS SPECIFIC voucher instance as used
                            $userVoucher->markAsUsed();
                            Log::info("Voucher applied: {$voucherCode} ({$voucher->percent}% discount: ₱{$voucherDiscount})");
                        } else {
                            Log::warning("Voucher template {$voucher->id} is disabled");
                        }
                    } else {
                        Log::warning("Voucher {$userVoucherId} is invalid (used or expired)");
                    }
                } else {
                    Log::warning("Voucher not found: {$userVoucherId} for user {$user->id}");
                }
            }

            $totalAmount = max(0, $subtotal + $shippingFee - $voucherDiscount);

            $order->update([
                'subtotal' => $subtotal,
                'total_amount' => $totalAmount,
                'voucher_id' => $voucherId,
                'voucher_code' => $voucherCode,
                'discount_amount' => $voucherDiscount,
            ]);

            // ✅ ENHANCED: Remove ALL cart items for this user after successful order
            // This ensures the cart is completely cleared regardless of the items sent
            $deletedCount = Cart::where('user_id', $user->id)->delete();
            
            Log::info("Cart cleared for user {$user->id}: {$deletedCount} items deleted (was {$initialCartCount})");

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully!',
                'order' => $order->load('items', 'voucher'),
                'cart_cleared' => $deletedCount,
                'initial_cart_count' => $initialCartCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order placement error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle regular product order item
     */
    private function handleRegularProductOrderItem($order, $item, &$subtotal)
    {
        $product = Product::find($item['product_id']);
        if (!$product) {
            Log::warning("Product not found: {$item['product_id']}");
            return;
        }

        // Parse sizes and prices if any
        $options = $this->parseJsonSafe($product->available_sizes);
        $prices  = $this->parseJsonSafe($product->prices);

        $sizePrice = null;
        $priceToUse = (float) ($product->price ?? 0);

        // Determine price based on size if applicable
        if ($options && $prices && count($options) === count($prices)) {
            if (!empty($item['size'])) {
                $index = array_search($item['size'], $options);
                if ($index !== false) {
                    $sizePrice = (float) $prices[$index];
                    $priceToUse = $sizePrice;
                }
            }
        }

        // Override with frontend values if valid
        if (!empty($item['price']) && $item['price'] > 0) {
            $priceToUse = (float) $item['price'];
        }
        if (!empty($item['size_price']) && $item['size_price'] > 0) {
            $sizePrice = (float) $item['size_price'];
        }

        // Ensure at least product base price
        if ($priceToUse <= 0) {
            $priceToUse = (float) ($product->price ?? 0);
        }
        if ($sizePrice === null && !empty($item['size'])) {
            $sizePrice = $priceToUse;
        }

        $subtotal += $priceToUse * $item['quantity'];

        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'name'       => $product->name,
            'price'      => $priceToUse,
            'size_price' => $sizePrice,
            'quantity'   => $item['quantity'],
            'size'       => $item['size'] ?? null,
            'image'      => $product->image,
            'is_customized' => false, // Regular product
        ]);

        Log::info("Regular product order item created: {$product->name} (Qty: {$item['quantity']}, Price: {$priceToUse})");
    }

    /**
     * Handle custom proposal order item - FIXED to properly mark ALL custom proposals as customized
     */
    private function handleCustomProposalOrderItem($order, $item, &$subtotal)
    {
        $proposal = CustomProposal::find($item['custom_proposal_id']);
        if (!$proposal) {
            Log::warning("Custom proposal not found: {$item['custom_proposal_id']}");
            return;
        }

        // Check if proposal belongs to user
        if ($proposal->customer_id !== $order->user_id) {
            Log::warning("User {$order->user_id} attempted to order unauthorized custom proposal {$proposal->id}");
            return;
        }

        // Determine price - use provided price or proposal price
        $priceToUse = (float) ($item['price'] ?? $proposal->total_price ?? 0);
        
        // If no price provided, use proposal total price
        if ($priceToUse <= 0) {
            $priceToUse = (float) ($proposal->total_price ?? 0);
        }

        // Handle size price for apparel custom proposals
        $sizePrice = null;
        if ($proposal->category === 'apparel' && !empty($item['size'])) {
            $sizePrice = $priceToUse; // For apparel, size price is the same as unit price
        }

        $subtotal += $priceToUse * $item['quantity'];

        // ✅ FIXED: Always mark custom proposals as customized, regardless of category
        OrderItem::create([
            'order_id' => $order->id,
            'custom_proposal_id' => $proposal->id, // Store custom proposal reference
            'name' => $proposal->name,
            'price' => $priceToUse,
            'size_price' => $sizePrice,
            'quantity' => $item['quantity'],
            'size' => $item['size'] ?? null,
            'image' => !empty($proposal->images) ? 
                (is_array($proposal->images) ? $proposal->images[0] : $proposal->images) : null,
            'is_customized' => true, // ✅ CRITICAL: Always mark as customized for ALL custom proposals
            'customization_details' => json_encode([
                'customization_request' => $proposal->customization_request,
                'designer_message' => $proposal->designer_message,
                'material' => $proposal->material,
                'features' => $proposal->features,
                'category' => $proposal->category,
            ]),
        ]);

        // ✅ Update custom proposal status to mark it as ordered
        $proposal->update([
            'status' => 'ordered',
            'order_id' => $order->id
        ]);

        Log::info("Custom proposal order item created: {$proposal->name} (Category: {$proposal->category}, Qty: {$item['quantity']}, Price: {$priceToUse})");
    }

    /**
     * Clear user's cart (separate endpoint if needed)
     */
    public function clearCart()
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        try {
            $deletedCount = Cart::where('user_id', $user->id)->delete();
            
            Log::info("Manual cart clearance for user {$user->id}: {$deletedCount} items deleted");

            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Cart clearance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's current cart count (for verification)
     */
    public function getCartCount()
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $cartCount = Cart::where('user_id', $user->id)->count();

        return response()->json([
            'success' => true,
            'cart_count' => $cartCount
        ]);
    }

    /**
     * List all orders for authenticated user - ENHANCED to include custom proposals
     */
    public function index()
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $orders = Order::with([
            'items.product', 
            'items.customProposal', // Load custom proposal relationship
            'voucher'
        ])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($orders);
    }

    /**
     * Show specific order details - ENHANCED to include custom proposals
     */
    public function show($id)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $order = Order::with([
            'items.product', 
            'items.customProposal', // Load custom proposal relationship
            'voucher'
        ])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json($order);
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'status' => 'required|string|in:pending,confirmed,processing,shipped,delivered,cancelled'
        ]);

        $order = Order::where('user_id', $user->id)->findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => $order
        ]);
    }

    /**
     * Helper: parse JSON safely
     */
    private function parseJsonSafe($data)
    {
        if (is_array($data)) return $data;
        if (is_string($data)) {
            $parsed = json_decode($data, true);
            return is_array($parsed) ? $parsed : [];
        }
        return [];
    }
}