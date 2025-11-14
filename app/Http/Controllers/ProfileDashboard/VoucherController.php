<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\UserVoucher;
use App\Models\Voucher;

class VoucherController extends Controller
{
    // Get only active (unused & unexpired) vouchers for the logged-in user
    public function myVouchers(Request $request)
    {
        $user = $request->user();

        $userVouchers = UserVoucher::with('voucher')
            ->where('user_id', $user->id)
            ->whereNull('used_at') // only unused vouchers
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($userVoucher) {
                $voucher = $userVoucher->voucher;
                
                // Calculate expiry for this specific instance
                if (!$userVoucher->expires_at && $voucher) {
                    $sentAt = $userVoucher->sent_at ?? $userVoucher->created_at;
                    $duration = (int) $voucher->expiration_duration;

                    $expiresAt = $voucher->expiration_type === 'hours'
                        ? $sentAt->copy()->addHours($duration)
                        : $sentAt->copy()->addDays($duration);

                    // Update this specific instance with calculated expiry
                    $userVoucher->update(['expires_at' => $expiresAt]);
                }

                // Prepare data for frontend
                return [
                    'id' => $userVoucher->id, // ✅ Use user_voucher ID, not voucher ID
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $userVoucher->voucher_code,
                    'name' => $voucher->name,
                    'description' => $voucher->description,
                    'percent' => $voucher->percent,
                    'image' => $voucher->image,
                    'status' => $voucher->status,
                    'expires_at' => $userVoucher->expires_at,
                    'is_expired' => $userVoucher->isExpired(),
                    'sent_at' => $userVoucher->sent_at,
                ];
            })
            ->filter(function ($voucherData) {
                // Filter out expired vouchers
                return !$voucherData['is_expired'];
            })
            ->values();

        return response()->json($userVouchers);
    }

    // Validate voucher during checkout
    public function validateVoucher(Request $request)
    {
        $request->validate([
            'user_voucher_id' => 'required|exists:user_voucher,id',
        ]);

        $user = $request->user();
        $userVoucher = UserVoucher::with('voucher')
            ->where('id', $request->user_voucher_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$userVoucher) {
            return response()->json(['error' => 'Voucher not found'], 404);
        }

        if ($userVoucher->isUsed()) {
            return response()->json(['error' => 'Voucher already used'], 400);
        }

        if ($userVoucher->isExpired()) {
            return response()->json(['error' => 'Voucher expired'], 400);
        }

        $voucher = $userVoucher->voucher;
        if (!$voucher->isActive()) {
            return response()->json(['error' => 'Voucher is not active'], 400);
        }

        return response()->json([
            'valid' => true,
            'voucher' => [
                'user_voucher_id' => $userVoucher->id,
                'voucher_id' => $voucher->id,
                'name' => $voucher->name,
                'percent' => $voucher->percent,
                'voucher_code' => $userVoucher->voucher_code,
            ]
        ]);
    }
}