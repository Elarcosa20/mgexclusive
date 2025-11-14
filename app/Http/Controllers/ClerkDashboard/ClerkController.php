<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class ClerkController extends Controller
{
    // Get logged-in clerk info
    public function me(Request $request)
    {
        // assuming JWT auth
        $clerk = auth()->user();

        if (!$clerk || $clerk->role !== 'clerk') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($clerk);
    }

    // Get all customers for clerk sidebar
    public function customers()
    {
        $customers = User::where('role', 'customer')->get();
        return response()->json($customers);
    }
}
