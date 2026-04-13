<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PosTierSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1;
        $now = Carbon::now();

        // Masukkan Tier Member POS
        $tiers = [
            [
                'business_id' => $businessId,
                'name' => 'Silver',
                'scope' => 'pos',
                'level_order' => 1,
                'min_points' => 0,     // Otomatis saat daftar
                'min_spend' => 0,
                'description' => 'Level awal untuk member baru.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'business_id' => $businessId,
                'name' => 'Gold',
                'scope' => 'pos',
                'level_order' => 2,
                'min_points' => 1000,  // Syarat naik ke Gold
                'min_spend' => 0,
                'description' => 'Member setia dengan keuntungan lebih.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'business_id' => $businessId,
                'name' => 'Platinum',
                'scope' => 'pos',
                'level_order' => 3,
                'min_points' => 5000,  // Syarat naik ke Platinum
                'min_spend' => 0,
                'description' => 'Member VIP prioritas utama.',
                'created_at' => $now, 'updated_at' => $now,
            ]
        ];

        DB::table('priority_levels')->insert($tiers);
    }
}
