<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WinBackVoucherSettingSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1;

        // Pastikan Anda sudah membuat DiscountRule untuk Win-Back (misal ID 7)
        $targetRuleId = 7;

        $now = Carbon::now();

        // 1. Setting ID Rule
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'winback_voucher_rule'],
            [
                'name' => 'WinBack Voucher Rule ID',
                'value' => (string)$targetRuleId,
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        // 2. Setting Threshold (Berapa hari tidak aktif?) -> Default 30
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'winback_threshold_days'],
            [
                'name' => 'WinBack Inactive Threshold (Days)',
                'value' => '30',
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        // 3. Setting Validitas Voucher (Misal 14 Hari)
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'winback_voucher_validity'],
            [
                'name' => 'WinBack Voucher Validity (Days)',
                'value' => '14',
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );
    }
}
