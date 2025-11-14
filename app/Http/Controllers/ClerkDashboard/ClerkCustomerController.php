<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class ClerkCustomerController extends Controller
{
    /**
     * Get current logged-in clerk info
     */
    public function me(Request $request)
    {
        // Assumes JWT auth: $request->user() returns authenticated clerk
        $clerk = $request->user();

        if (!$clerk) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'id' => $clerk->id,
            'name' => $clerk->name,
            'email' => $clerk->email,
            'role' => $clerk->role,
            'profile_picture' => $clerk->profile_image
                ? url('storage/' . $clerk->profile_image)
                : null,
        ]);
    }

    /**
     * Fetch all customers relevant to clerks (Customers, VIPs, Organizations)
     */
    public function index(Request $request)
    {
        try {
            $query = Customer::query()->whereIn('role', ['customer', 'vip', 'organization']);

            // Optional search filter
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%")
                      ->orWhere('organization_name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                });
            }

            $customers = $query->get()->map(function ($c) {
                $displayName = $c->is_organization
                    ? ($c->organization_name ?? 'Unnamed Organization')
                    : trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));

                return [
                    'id' => $c->id,
                    'display_name' => $displayName,
                    'email' => $c->email ?? '',
                    'role' => $c->role ?? 'customer',
                    'is_organization' => (bool) $c->is_organization,
                    'organization_name' => $c->organization_name,
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'phone' => $c->phone,
                    'address' => $c->address,
                    'dob' => $c->dob,
                    'date_founded' => $c->date_founded,
                    'business_type' => $c->business_type,
                    'industry' => $c->industry,
                    'created_at' => $c->created_at,
                    'total_orders' => $c->total_orders ?? 0,
                    'total_spent' => $c->total_spent ?? 0,
                    'profile_picture' => $c->profile_image
                        ? url('storage/' . $c->profile_image)
                        : null,
                ];
            });

            return response()->json($customers);

        } catch (\Exception $e) {
            \Log::error('ClerkCustomerController@index error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch customers: ' . $e->getMessage()
            ], 500);
        }
    }
}
