<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AddressController extends Controller
{
    // Show all addresses for logged-in user
    public function index(Request $request)
{
    $user = auth('api')->user(); // JWT authentication
    return response()->json($user->addresses()->whereNull('deleted_at')->get());
}


    // Add new address
    public function store(Request $request)
    {
        $user = auth('api')->user();

        $validated = $request->validate([
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'required|string|max:20',
            'main_address'     => 'required|string|max:255',
            'specific_address' => 'nullable|string|max:255',
        ]);

        $address = $user->addresses()->create($validated);

        return response()->json($address, 201);
    }

    // Update existing address
    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        $address = $user->addresses()->findOrFail($id);

        $validated = $request->validate([
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'required|string|max:20',
            'main_address'     => 'required|string|max:255',
            'specific_address' => 'nullable|string|max:255',
        ]);

        $address->update($validated);

        return response()->json($address);
    }

    // Delete an address
  public function destroy($id)
{
    $user = auth('api')->user();
    $address = $user->addresses()->findOrFail($id);
    $address->delete(); // With SoftDeletes, this sets deleted_at

    return response()->json(['message' => 'Address soft deleted successfully']);
}

}
