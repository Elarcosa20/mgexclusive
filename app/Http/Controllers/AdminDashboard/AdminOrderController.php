<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminOrderController extends Controller
{
    /**
     * Get all orders for admin management
     */
    public function index(Request $request)
    {
        Log::info('AdminOrderController: index method called');
        
        try {
            // Check if user is authenticated
            $user = auth('api')->user();
            
            if (!$user) {
                Log::warning('AdminOrderController: Unauthorized access attempt');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            Log::info('AdminOrderController: Fetching orders for user ID: ' . $user->id);

            // Get orders with related data - ENHANCED to include custom proposals
            $orders = Order::with([
                'items.product', 
                'items.customProposal', // Added custom proposal relationship
                'voucher', 
                'user'
            ])->orderBy('created_at', 'desc')->get();

            Log::info('AdminOrderController: Found ' . $orders->count() . ' orders');

            // Transform the orders to include customer information
            $transformedOrders = $orders->map(function ($order) {
                return $this->transformOrder($order);
            });

            return response()->json([
                'success' => true,
                'data' => $transformedOrders,
                'total' => $orders->count()
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController Error: ' . $e->getMessage());
            Log::error('AdminOrderController Stack Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific order details for admin
     */
    public function show($id)
    {
        Log::info('AdminOrderController: show method called for order ID: ' . $id);
        
        try {
            $user = auth('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $order = Order::with(['items.product', 'items.customProposal', 'voucher', 'user'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->transformOrder($order)
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController Show Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Update order status (admin)
     */
    public function updateStatus(Request $request, $id)
    {
        Log::info('AdminOrderController: updateStatus method called for order ID: ' . $id);
        
        try {
            $user = auth('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // ✅ UPDATED: Remove 'cancelled' from allowed statuses
            $request->validate([
                'status' => 'required|string|in:pending,confirmed,processing,packaging,on_delivery,delivered'
            ]);

            $order = Order::findOrFail($id);
            $order->status = $request->status;
            $order->save();

            Log::info("Order {$id} status updated to: {$request->status}");

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => $this->transformOrder($order)
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController UpdateStatus Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform order data for admin response - ENHANCED to handle custom proposals
     */
    private function transformOrder($order)
    {
        try {
            $customerData = [];
            
            // Extract customer information from the customer JSON field
            if ($order->customer) {
                if (is_string($order->customer)) {
                    try {
                        $customerData = json_decode($order->customer, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $customerData = [];
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse customer JSON for order ' . $order->id . ': ' . $e->getMessage());
                        $customerData = [];
                    }
                } elseif (is_array($order->customer)) {
                    $customerData = $order->customer;
                }
            }

            // Safely get items data - ENHANCED to handle custom proposals
            $items = [];
            if ($order->items) {
                $items = $order->items->map(function ($item) {
                    $productData = null;
                    $customProposalData = null;
                    
                    // Handle regular product
                    if ($item->product) {
                        $productData = [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'description' => $item->product->description ?? '',
                            'price' => floatval($item->product->price ?? 0),
                            'color' => $item->product->color ?? '',
                            'images' => $item->product->images ? (is_string($item->product->images) ? json_decode($item->product->images, true) : $item->product->images) : [],
                            'image' => $item->product->image ?? '',
                        ];
                    }
                    
                    // Handle custom proposal
                    if ($item->customProposal) {
                        $customProposalData = [
                            'id' => $item->customProposal->id,
                            'name' => $item->customProposal->name,
                            'category' => $item->customProposal->category,
                            'material' => $item->customProposal->material,
                            'customization_request' => $item->customProposal->customization_request,
                            'images' => $item->customProposal->images ? (is_string($item->customProposal->images) ? json_decode($item->customProposal->images, true) : $item->customProposal->images) : [],
                            'total_price' => floatval($item->customProposal->total_price ?? 0),
                        ];
                    }

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'custom_proposal_id' => $item->custom_proposal_id,
                        'name' => $item->name,
                        'price' => floatval($item->price ?? 0),
                        'size_price' => $item->size_price ? floatval($item->size_price) : null,
                        'quantity' => $item->quantity,
                        'size' => $item->size,
                        'image' => $item->image,
                        'is_customized' => $item->is_customized,
                        'product' => $productData,
                        'custom_proposal' => $customProposalData
                    ];
                });
            }

            // Safely get voucher data
            $voucherData = null;
            if ($order->voucher) {
                $voucherData = [
                    'id' => $order->voucher->id,
                    'name' => $order->voucher->name,
                    'percent' => $order->voucher->percent,
                ];
            }

            // Safely get user data
            $userData = null;
            if ($order->user) {
                $userData = [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ];
            }

            return [
                'id' => $order->id,
                'order_number' => $order->id,
                'user_id' => $order->user_id,
                'customer' => $customerData,
                'subtotal' => floatval($order->subtotal ?? 0),
                'shipping_fee' => floatval($order->shipping_fee ?? 0),
                'total_amount' => floatval($order->total_amount ?? 0),
                'voucher_id' => $order->voucher_id,
                'voucher_code' => $order->voucher_code,
                'discount_amount' => floatval($order->discount_amount ?? 0),
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'items' => $items,
                'voucher' => $voucherData,
                'user' => $userData
            ];

        } catch (\Exception $e) {
            Log::error('TransformOrder Error for order ' . ($order->id ?? 'unknown') . ': ' . $e->getMessage());
            return [
                'id' => $order->id ?? 0,
                'order_number' => $order->id ?? 0,
                'user_id' => $order->user_id ?? 0,
                'customer' => [],
                'subtotal' => 0,
                'shipping_fee' => 0,
                'total_amount' => 0,
                'status' => $order->status ?? 'unknown',
                'created_at' => $order->created_at ?? now(),
                'items' => []
            ];
        }
    }
}