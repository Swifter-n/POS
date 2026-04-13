<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GameRewardSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1; // Sesuaikan dengan Business ID Anda
        $now = Carbon::now();

        // 1. Setting Harga Main: 50 Poin
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'game_ticket_price'],
            [
                'name' => 'Game Ticket Price (Points)',
                'value' => '50',
                // === PERBAIKAN: Tambahkan charge_type ===
                'charge_type' => 'fixed',
                // ========================================
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 2. Data Hadiah Game (Game Rewards)

        // Hadiah 1: ZONK (Peluang Besar 50)
        DB::table('game_rewards')->insert([
            'business_id' => $businessId,
            'name' => 'Coba Lagi',
            'type' => 'zonk',
            'discount_rule_id' => null,
            'point_reward' => 0,
            'probability' => 50,
            'color_code' => '#808080', // Abu-abu
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Hadiah 2: Diskon Kecil (Peluang 30) -> Hubungkan ke Rule ID 5 (Potongan 5rb)
        // Pastikan ID 5 ada di tabel discount_rules Anda
        DB::table('game_rewards')->insert([
            'business_id' => $businessId,
            'name' => 'Potongan 5K',
            'type' => 'voucher',
            'discount_rule_id' => 5,
            'point_reward' => 0,
            'probability' => 30,
            'color_code' => '#3498db', // Biru
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Hadiah 3: JACKPOT BOGO (Peluang 5) -> Hubungkan ke Rule ID 8 (BOGO)
        // Pastikan ID 8 ada di tabel discount_rules Anda
        DB::table('game_rewards')->insert([
            'business_id' => $businessId,
            'name' => 'JACKPOT BOGO!',
            'type' => 'voucher',
            'discount_rule_id' => 8,
            'point_reward' => 0,
            'probability' => 5,
            'color_code' => '#f1c40f', // Emas
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Hadiah 4: Menang Poin (Bonus 20 Poin) - Peluang 15
        DB::table('game_rewards')->insert([
            'business_id' => $businessId,
            'name' => 'Bonus 20 Poin',
            'type' => 'point',
            'discount_rule_id' => null,
            'point_reward' => 20,
            'probability' => 15,
            'color_code' => '#2ecc71', // Hijau
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
