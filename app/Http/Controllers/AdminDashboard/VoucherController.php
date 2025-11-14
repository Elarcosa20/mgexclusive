<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Voucher;
use App\Models\UserVoucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class VoucherController extends Controller
{
    // List all vouchers
    public function index()
    {
        $vouchers = Voucher::all()->map(function ($voucher) {
            $voucher->is_expired = false;
            $voucher->expires_at = null;
            return $voucher;
        });

        return response()->json($vouchers);
    }

    // ✅ NEW METHOD: Get enabled vouchers for public banner
    public function enabledVouchers()
    {
        try {
            \Log::info('Fetching enabled vouchers for public banner...');
            
            $vouchers = Voucher::where('status', 'enabled')
                ->where(function($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->select([
                    'id', 
                    'name', 
                    'description', 
                    'percent as discount_percentage', 
                    'image', 
                    'expires_at',
                    'expiration_type',
                    'expiration_duration'
                ])
                ->get()
                ->map(function($voucher) {
                    return [
                        'id' => $voucher->id,
                        'name' => $voucher->name,
                        'description' => $voucher->description,
                        'discount_percentage' => $voucher->discount_percentage,
                        'image' => $voucher->image ? Storage::url($voucher->image) : null,
                        'expires_at' => $voucher->expires_at,
                        'expiration_type' => $voucher->expiration_type,
                        'expiration_duration' => $voucher->expiration_duration,
                        'link' => '/products' // Default link to products page
                    ];
                });

            \Log::info('Successfully fetched ' . $vouchers->count() . ' enabled vouchers');
            
            return response()->json($vouchers);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch enabled vouchers: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Return empty array instead of 500 error
            return response()->json([]);
        }
    }

    // Create a new voucher
    public function store(Request $request)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'percent'             => 'required|numeric|min:1|max:100',
            'image'               => 'nullable|image|max:2048',
            'expiration_type'     => 'required|string|in:hours,days',
            'expiration_duration' => 'required|numeric|min:1',
        ]);

        $imagePath = $request->file('image')?->store('vouchers', 'public');

        $voucher = Voucher::create([
            'name'                => $request->name,
            'description'         => $request->description,
            'percent'             => $request->percent,
            'image'               => $imagePath,
            'status'              => 'enabled',
            'expiration_type'     => $request->expiration_type,
            'expiration_duration' => (int) $request->expiration_duration,
        ]);

        return response()->json(['message' => 'Voucher created successfully.', 'voucher' => $voucher]);
    }

    // ✅ Update voucher
    public function update(Request $request, $id)
    {
        $voucher = Voucher::findOrFail($id);

        $request->validate([
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'percent'             => 'required|numeric|min:1|max:100',
            'image'               => 'nullable|image|max:2048',
            'expiration_type'     => 'required|string|in:hours,days',
            'expiration_duration' => 'required|numeric|min:1',
        ]);

        // Handle image update (optional)
        if ($request->hasFile('image')) {
            if ($voucher->image) {
                Storage::disk('public')->delete($voucher->image);
            }
            $voucher->image = $request->file('image')->store('vouchers', 'public');
        }

        $voucher->update([
            'name'                => $request->name,
            'description'         => $request->description,
            'percent'             => $request->percent,
            'expiration_type'     => $request->expiration_type,
            'expiration_duration' => (int) $request->expiration_duration,
        ]);

        return response()->json(['message' => 'Voucher updated successfully.', 'voucher' => $voucher]);
    }

    // Send voucher to a user
    public function send(Request $request, $id)
    {
        $request->validate([
            'customer_id' => 'required|exists:users,id',
        ]);

        $voucher = Voucher::findOrFail($id);
        $user = User::findOrFail($request->customer_id);

        $sentAt = now();
        $expiresAt = $voucher->expiration_type === 'hours'
            ? $sentAt->copy()->addHours($voucher->expiration_duration)
            : $sentAt->copy()->addDays($voucher->expiration_duration);

        $userVoucher = UserVoucher::create([
            'user_id' => $user->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->generateVoucherCode(),
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'message' => 'Voucher sent successfully.',
            'voucher_instance' => [
                'id' => $userVoucher->id,
                'voucher_code' => $userVoucher->voucher_code,
                'expires_at' => $expiresAt,
            ],
        ]);
    }

    // Toggle enable/disable
    public function toggleStatus($id)
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->status = $voucher->status === 'enabled' ? 'disabled' : 'enabled';
        $voucher->save();

        return response()->json(['message' => 'Voucher status updated.', 'voucher' => $voucher]);
    }
}