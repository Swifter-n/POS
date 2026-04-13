<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RewardCatalog;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    /**
     * GET /api/v1/pos/rewards
     * Mengambil daftar katalog hadiah yang aktif.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $rewards = RewardCatalog::where('business_id', $user->business_id)
            ->where('is_active', true)
            ->with('discountRule') // Load rule untuk info detail
            ->orderBy('points_required', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rewards
        ]);
    }

    /**
     * POST /api/v1/pos/rewards/{id}/redeem
     * Menukarkan Poin Member menjadi Voucher.
     */
    public function redeem(Request $request, $id)
    {
        $request->validate([
            'member_id' => 'required|integer|exists:members,id',
        ]);

        $user = $request->user();

        // 1. Ambil Data Hadiah
        $reward = RewardCatalog::where('business_id', $user->business_id)
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (!$reward) {
            return response()->json(['message' => 'Hadiah tidak ditemukan atau tidak aktif.'], 404);
        }

        // 2. Ambil Data Member
        $member = Member::where('id', $request->member_id)
            ->where('business_id', $user->business_id)
            ->first();

        if (!$member) {
             return response()->json(['message' => 'Member tidak valid.'], 404);
        }

        // 3. Cek Poin Cukup?
        if ($member->current_points < $reward->points_required) {
            return response()->json(['message' => 'Poin tidak mencukupi.'], 400);
        }

        // 4. Proses Transaksi (Atomik)
        try {
            $voucher = DB::transaction(function () use ($member, $reward) {
                // A. Potong Poin
                $member->decrement('current_points', $reward->points_required);

                // B. Terbitkan Voucher (Panggil Service yang sudah kita buat)
                // Masa berlaku bisa diambil dari DiscountRule atau default 30 hari
                // Di sini kita ambil default 30 hari untuk hasil redeem
                $voucher = VoucherService::issueVoucher(
                    $member,
                    $reward->discountRule,
                    30 // Valid 30 hari sejak redeem
                );

                return $voucher;
            });

            return response()->json([
                'success' => true,
                'message' => 'Berhasil menukarkan poin!',
                'data' => [
                    'voucher_code' => $voucher->code,
                    'remaining_points' => $member->current_points,
                    'valid_until' => $voucher->valid_until,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal redeem: ' . $e->getMessage()], 500);
        }
    }
}
