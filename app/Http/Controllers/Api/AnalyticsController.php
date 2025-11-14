<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class AnalyticsController extends Controller
{
    public function customersCount()
    {
        try {
            // Count all non-admin, non-clerk users (customers + VIPs)
            $customerCount = User::where(function ($query) {
                $query->whereNotIn('role', ['admin', 'clerk'])
                      ->orWhereNull('role');
            })->count();

            return response()->json([
                'count' => $customerCount,
                'total_customers' => $customerCount
            ]);
        } catch (\Exception $e) {
            \Log::error('Error counting customers: ' . $e->getMessage());

            return response()->json([
                'count' => 1000,
                'total_customers' => 1000,
                'message' => 'Using default count'
            ]);
        }
    }

    public function popularSearches()
    {
        return response()->json([
            'backpack',
            'duffle bag',
            'sports wear',
            'tote',
            'basketball',
            'volleyball',
            'gym bag',
            'travel bag'
        ]);
    }
}