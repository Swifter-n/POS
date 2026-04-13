<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WelcomeVoucherSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Ganti '1' dengan Business ID Anda
        $businessId = 1;

        // Ganti '5' dengan ID dari Discount Rule yang ingin dijadikan Welcome Promo
        // (Pastikan ID 5 ada di tabel discount_rules)
        $targetRuleId = 5;

        $now = Carbon::now();

        // 1. Setting untuk ID Rule
        DB::table('business_settings')->updateOrInsert(
            [
                'business_id' => $businessId,
                'type' => 'welcome_voucher_rule', // Key Identifikasi
            ],
            [
                'name' => 'Welcome Voucher Rule ID',
                'value' => (string)$targetRuleId,
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 2. Setting untuk Durasi Hari (Default 7 hari)
        DB::table('business_settings')->updateOrInsert(
            [
                'business_id' => $businessId,
                'type' => 'welcome_voucher_days', // Key Identifikasi
            ],
            [
                'name' => 'Welcome Voucher Validity Days',
                'value' => '7',
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
