<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    // GET /api/admin/customers
    public function index(Request $request)
    {
        $search = $request->query('search', '');

        $customers = User::where(function ($query) {
                $query->whereNotIn('role', ['admin', 'clerk'])
                      ->orWhereNull('role'); // include customers without role
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereRaw("COALESCE(display_name, CONCAT_WS(' ', first_name, last_name)) LIKE ?", ["%{$search}%"])
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($c) {
                // Ensure display_name is always set
                $c->display_name = $c->display_name ?? trim("{$c->first_name} {$c->last_name}") ?: $c->email;

                // Ensure is_organization is boolean
                $c->is_organization = $c->is_organization ?? false;

                // Fix profile picture path
                if ($c->profile_picture) {
                    // If stored in storage/app/public, generate public URL
                    $c->profile_picture = asset('storage/' . $c->profile_picture);
                } else {
                    $c->profile_picture = null;
                }

                // Optional: defaults for missing fields
                $c->phone = $c->phone ?? null;
                $c->address = $c->address ?? null;
                $c->dob = $c->dob ?? null;
                $c->date_founded = $c->date_founded ?? null;
                $c->business_type = $c->business_type ?? null;
                $c->industry = $c->industry ?? null;
                $c->total_orders = $c->total_orders ?? 0;
                $c->total_spent = $c->total_spent ?? 0;

                return $c;
            });

        return response()->json($customers);
    }

    // PUT /api/admin/customers/{id}/promote
    public function promote($id)
    {
        $customer = User::whereNotIn('role', ['admin', 'clerk'])->findOrFail($id);

        if ($customer->role === 'vip') {
            return response()->json(['message' => 'Customer is already VIP'], 400);
        }

        $customer->role = 'vip';
        $customer->save();

        // Return customer with full profile_picture URL
        if ($customer->profile_picture) {
            $customer->profile_picture = asset('storage/' . $customer->profile_picture);
        } else {
            $customer->profile_picture = null;
        }

        return response()->json(['message' => 'Customer promoted to VIP', 'customer' => $customer]);
    }

    // GET /api/admin/customers/{id}/purchase-stats
    public function getPurchaseStats($id)
    {
        try {
            $customer = User::whereNotIn('role', ['admin', 'clerk'])->findOrFail($id);

            Log::info("Fetching purchase stats for customer ID: {$id}");

            // Count total orders for this customer
            $purchaseCount = Order::where('user_id', $id)->count();
            Log::info("Total orders found: {$purchaseCount}");

            // Calculate total amount spent - check different possible statuses and fields
            $totalSpent = Order::where('user_id', $id)
                ->where(function($query) {
                    // Check multiple possible status fields and values
                    $query->where('payment_status', 'paid')
                          ->orWhere('status', 'delivered')
                          ->orWhere('status', 'completed')
                          ->orWhere('payment_status', 'completed');
                })
                ->sum('total_amount');

            // If no results with status filters, try without filters
            if (!$totalSpent) {
                $totalSpent = Order::where('user_id', $id)->sum('total_amount');
                Log::info("No paid orders found, using all orders total: {$totalSpent}");
            }

            // Debug: Check individual orders
            $orders = Order::where('user_id', $id)->get(['id', 'total_amount', 'payment_status', 'status']);
            Log::info("Customer orders:", $orders->toArray());

            Log::info("Final calculated total spent: {$totalSpent}");

            return response()->json([
                'purchase_count' => $purchaseCount,
                'total_spent' => floatval($totalSpent)
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching purchase stats for customer {$id}: " . $e->getMessage());
            return response()->json([
                'purchase_count' => 0,
                'total_spent' => 0
            ], 500);
        }
    }
}