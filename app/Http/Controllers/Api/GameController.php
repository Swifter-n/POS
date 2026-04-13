<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\GameReward;
use App\Models\Member;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GameController extends Controller
{
    /**
     * GET /api/v1/pos/game/config
     * Mengambil daftar hadiah untuk ditampilkan di Roda (Wheel) dan Harga Tiket.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Ambil Harga Tiket
        $ticketSetting = BusinessSetting::where('business_id', $user->business_id)
            ->where('type', 'game_ticket_price')
            ->first();
        $ticketPrice = $ticketSetting ? (int)$ticketSetting->value : 50;

        // 2. Ambil Daftar Hadiah
        $rewards = GameReward::where('business_id', $user->business_id)
            ->where('is_active', true)
            ->get()
            ->map(function ($reward) {
                return [
                    'id' => $reward->id,
                    'name' => $reward->name,
                    'color' => $reward->color_code,
                    'type' => $reward->type, // voucher, point, zonk
                    'probability' => $reward->probability, // Untuk visualisasi ukuran juring (opsional)
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'ticket_price' => $ticketPrice,
                'rewards' => $rewards
            ]
        ]);
    }

    /**
     * POST /api/v1/pos/game/spin
     * Melakukan Spin, Mengurangi Poin, dan Memberikan Hadiah.
     */
    public function spin(Request $request)
    {
        $request->validate([
            'member_id' => 'required|exists:members,id',
        ]);

        $user = $request->user();

        try {
            return DB::transaction(function () use ($request, $user) {
                // 1. Validasi Member & Poin
                $member = Member::lockForUpdate()->find($request->member_id);

                // Cek Harga Tiket
                $ticketSetting = BusinessSetting::where('business_id', $user->business_id)
                    ->where('type', 'game_ticket_price')->first();
                $ticketPrice = $ticketSetting ? (int)$ticketSetting->value : 50;

                if ($member->current_points < $ticketPrice) {
                    return response()->json(['message' => "Poin tidak cukup. Butuh $ticketPrice poin."], 400);
                }

                // 2. Potong Poin Tiket
                $member->decrement('current_points', $ticketPrice);

                // 3. ALGORITMA GACHA (Weighted Random)
                $rewards = GameReward::where('business_id', $user->business_id)
                    ->where('is_active', true)
                    ->get();

                if ($rewards->isEmpty()) {
                    throw new \Exception("Konfigurasi hadiah belum ada.");
                }

                $chosenReward = $this->pickWeightedReward($rewards);

                // 4. Berikan Hadiah
                $rewardMessage = "Maaf, coba lagi!";
                $voucherCode = null;

                if ($chosenReward->type === 'voucher' && $chosenReward->discount_rule_id) {
                    // Issue Voucher
                    $rule = $chosenReward->discountRule;
                    if ($rule) {
                        $voucher = VoucherService::issueVoucher($member, $rule, 7); // Valid 7 hari
                        $rewardMessage = "Selamat! Kamu dapat Voucher {$rule->name}";
                        $voucherCode = $voucher->code;
                    }
                }
                elseif ($chosenReward->type === 'point') {
                    // Tambah Poin
                    $points = $chosenReward->point_reward;
                    if ($points > 0) {
                        $member->increment('current_points', $points);
                        $rewardMessage = "Selamat! Kamu dapat Bonus $points Poin";
                    }
                }
                elseif ($chosenReward->type === 'zonk') {
                    $rewardMessage = "Yah, kurang beruntung. Coba lagi!";
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'reward_id' => $chosenReward->id,
                        'reward_name' => $chosenReward->name,
                        'reward_type' => $chosenReward->type,
                        'message' => $rewardMessage,
                        'voucher_code' => $voucherCode,
                        'remaining_points' => $member->fresh()->current_points,
                    ]
                ]);
            });

        } catch (\Exception $e) {
            Log::error("Game Spin Error: " . $e->getMessage());
            return response()->json(['message' => 'Gagal memutar roda: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper: Pilih hadiah berdasarkan bobot probabilitas
     */
    private function pickWeightedReward($rewards)
    {
        $totalWeight = $rewards->sum('probability');
        $random = rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($rewards as $reward) {
            $currentWeight += $reward->probability;
            if ($random <= $currentWeight) {
                return $reward;
            }
        }

        return $rewards->first(); // Fallback (seharusnya tidak terpanggil)
    }
}
