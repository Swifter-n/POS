<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BirthdayVoucherSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Sesuaikan dengan Business ID dan Rule ID yang Anda mau
        $businessId = 1;

        // Pastikan Anda sudah membuat DiscountRule baru untuk ultah, misal ID 6
        // atau gunakan yang ada.
        $targetRuleId = 5;

        $now = Carbon::now();

        // 1. Setting ID Rule Ulang Tahun
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'birthday_voucher_rule'],
            [
                'name' => 'Birthday Voucher Rule ID',
                'value' => (string)$targetRuleId,
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        // 2. Setting Masa Berlaku (Misal 30 Hari)
        DB::table('business_settings')->updateOrInsert(
            ['business_id' => $businessId, 'type' => 'birthday_voucher_days'],
            [
                'name' => 'Birthday Voucher Validity Days',
                'value' => '30',
                'charge_type' => 'fixed',
                'status' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );
    }
}
