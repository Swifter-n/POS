<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PointSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1;
        $now = Carbon::now();

        // 1. Setting Nilai Tukar (Redemption Rate)
        // Contoh: 1 Poin = Rp 1
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'point_exchange_rate'],
            [
                'name' => 'Point Exchange Rate (Rp per 1 Point)',
                'value' => '1',
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        // 2. Setting Batas Maksimum Penggunaan Poin (Optional)
        // Misal: Maksimal 100% dari total tagihan (1.0) atau 50% (0.5)
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'max_point_redemption_percent'],
            [
                'name' => 'Max Point Redemption (%)',
                'value' => '100', // Bisa bayar full pakai poin
                'charge_type' => 'percentage',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );
    }
}
