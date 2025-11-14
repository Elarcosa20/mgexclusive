<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;

class VIPCustomerController extends Controller
{
    /**
     * List all VIP customers
     */
    public function index()
    {
        $vips = User::where('role', 'vip')->get();

        // Add display_name + profile_picture for frontend convenience
        $vips->map(function ($v) {
            $v->display_name = $v->is_organization
                ? $v->organization_name
                : trim($v->first_name . ' ' . $v->last_name);

            // ✅ fix: convert profile_image to full URL and map to profile_picture
            $v->profile_picture = $v->profile_image
                ? url('storage/' . $v->profile_image)
                : null;

            return $v;
        });

        return response()->json($vips);
    }

    /**
     * Remove VIP status from a customer
     */
    public function remove($id)
    {
        $customer = User::findOrFail($id);

        if ($customer->role !== 'vip') {
            return response()->json(['message' => 'User is not a VIP'], 400);
        }

        $customer->role = 'customer';
        $customer->save();

        // Add display_name + profile_picture for frontend convenience
        $customer->display_name = $customer->is_organization
            ? $customer->organization_name
            : trim($customer->first_name . ' ' . $customer->last_name);

        $customer->profile_picture = $customer->profile_image
            ? url('storage/' . $customer->profile_image)
            : null;

        return response()->json($customer);
    }
}
