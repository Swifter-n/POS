<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\PointRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PointService
{
    /**
     * Menghitung Total Poin yang didapat dari sebuah Order.
     */
    public static function calculateEarnedPoints(Order $order): int
    {
        // 1. Hitung Base Points (Rp X = 1 Poin)
        // Default: Rp 10.000 = 1 Poin
        $exchangeRate = 10000;
        $setting = BusinessSetting::where('business_id', $order->business_id)
            ->where('type', 'point_exchange_rate')->first();
        if ($setting) {
            $exchangeRate = (float) $setting->value;
        }

        // Poin dasar dari Grand Total
        $basePoints = floor($order->total_price / ($exchangeRate > 0 ? $exchangeRate : 10000));

        // 2. Cek Point Rules (Bonus/Multiplier)
        $now = Carbon::now();
        $rules = PointRule::where('business_id', $order->business_id)
            ->where('is_active', true)
            ->where(fn($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $now))
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $now))
            ->get();

        $bonusPoints = 0;
        $multiplier = 1;

        // Load items untuk cek syarat produk
        $order->load('items');

        foreach ($rules as $rule) {
            $conditions = $rule->conditions ?? [];

            // === LOGIC VALIDASI HARI & JAM (TAMBAHAN BARU) ===

            // 1. Cek Hari (Days)
            // Contoh JSON: {"days": ["Monday", "Saturday", "Sunday"]}
            if (isset($conditions['days']) && is_array($conditions['days']) && !empty($conditions['days'])) {
                $currentDay = $now->format('l'); // Mengembalikan 'Monday', 'Tuesday', dst.
                // Gunakan in_array dengan pengecekan case-insensitive (opsional, tapi aman)
                $isDayMatch = false;
                foreach ($conditions['days'] as $day) {
                    if (strcasecmp($day, $currentDay) === 0) {
                        $isDayMatch = true;
                        break;
                    }
                }

                if (!$isDayMatch) {
                    continue; // Skip rule ini jika hari tidak cocok
                }
            }

            // 2. Cek Jam (Happy Hour)
            // Contoh JSON: {"start_time": "14:00", "end_time": "17:00"}
            if (isset($conditions['start_time']) && isset($conditions['end_time'])) {
                try {
                    $startTime = Carbon::createFromTimeString($conditions['start_time']);
                    $endTime = Carbon::createFromTimeString($conditions['end_time']);

                    // Cek apakah sekarang berada di luar rentang waktu
                    if (!$now->between($startTime, $endTime)) {
                        continue; // Skip rule ini jika jam tidak cocok
                    }
                } catch (\Exception $e) {
                    Log::warning("PointRule Time Format Error (ID: {$rule->id}): " . $e->getMessage());
                    // Jika format jam salah, kita skip atau lanjut? Aman-nya skip agar tidak bocor.
                    continue;
                }
            }
            // =================================================

            switch ($rule->type) {
                case 'transaction_multiplier':
                    // Contoh: Happy Hour / Weekend 2x Poin
                    // Logic hari/jam sudah dicek di atas, jadi langsung apply jika lolos
                    $multiplier = max($multiplier, (float)$rule->value);
                    break;

                case 'product_bonus':
                    // Contoh: Beli Kopi Susu (ID 1) dapat +10 Poin
                    $targetProductId = $conditions['product_id'] ?? null;
                    if ($targetProductId) {
                        foreach ($order->items as $item) {
                            if ($item->product_id == $targetProductId) {
                                // Bonus per qty
                                $bonusPoints += ($rule->value * $item->quantity);
                            }
                        }
                    }
                    break;

                case 'category_bonus':
                    // Contoh: Beli Makanan (Cat ID 5) dapat +5 Poin
                    $targetCatId = $conditions['category_id'] ?? null;
                    if ($targetCatId) {
                         foreach ($order->items as $item) {
                            // Pastikan relasi product terload, atau load manual jika perlu
                            // Di sini kita asumsi $item->product sudah diload oleh caller atau eloquent
                            if ($item->product && $item->product->category_id == $targetCatId) {
                                $bonusPoints += ($rule->value * $item->quantity);
                            }
                        }
                    }
                    break;
            }
        }

        // Rumus Akhir: (Base Poin * Multiplier) + Bonus
        $totalPoints = floor($basePoints * $multiplier) + $bonusPoints;

        Log::info("POINT CALC | Order: {$order->order_number} | Base: $basePoints | Mult: $multiplier | Bonus: $bonusPoints | Total: $totalPoints");

        return (int) $totalPoints;
    }
}
