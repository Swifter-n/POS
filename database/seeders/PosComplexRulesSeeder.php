<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PosComplexRulesSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1; // Sesuaikan dengan user Anda
        $now = Carbon::now();

        $rules = [
            // ============================================================
            // SKENARIO 1: Minimum Order (Belanja Total)
            // Logic: Total belanja >= 100.000, Diskon 10%
            // ============================================================
            [
                'business_id' => $businessId,
                'name' => 'MIN_BELANJA_100K',
                'priority' => 10,
                'is_active' => true,
                'applicable_for' => 'pos',

                // Tipe & JSON Kondisi
                'type' => 'minimum_purchase',
                'condition_value' => json_encode([
                    'amount' => 100000 // Syarat total belanja
                ]),

                // Reward
                'discount_type' => 'percentage',
                'discount_value' => 10, // Diskon 10% dari total

                // Field lain (Null karena pakai JSON)
                'product_id' => null,
                'min_quantity' => null,
                'is_cumulative' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // ============================================================
            // SKENARIO 2: BOGO (Buy 1 Get 1 - Same Item)
            // Logic: Beli 2 item ID 1, bayar 1. (Buy 1 Get 1 Free)
            // ============================================================
            [
                'business_id' => $businessId,
                'name' => 'BOGO_KOPI_SUSU',
                'priority' => 20,
                'is_active' => true,
                'applicable_for' => 'pos',

                // Tipe & JSON Kondisi
                'type' => 'bogo_same_item',
                'condition_value' => json_encode([
                    'product_id' => 1,    // ID Produk target (Pastikan ID ini ada)
                    'buy_quantity' => 1,  // Syarat beli
                    'get_quantity' => 1   // Yang didapat gratis
                ]),

                // Reward (Biasanya 100% untuk item gratisan, tapi logic di code menghitung manual)
                'discount_type' => 'percentage',
                'discount_value' => 100,

                'product_id' => null,
                'min_quantity' => null,
                'is_cumulative' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // ============================================================
            // SKENARIO 3: Buy X Get Y (Bundling)
            // Logic: Beli Produk ID 1, dapat Diskon 50% untuk Produk ID 2
            // ============================================================
            [
                'business_id' => $businessId,
                'name' => 'BUNDLE_SARAPAN',
                'priority' => 15,
                'is_active' => true,
                'applicable_for' => 'pos',

                // Tipe & JSON Kondisi
                'type' => 'buy_x_get_y',
                'condition_value' => json_encode([
                    'buy_product_id' => 3, // Syarat: Beli Kopi
                    'get_product_id' => 1  // Reward: Diskon di Roti
                ]),

                // Reward
                'discount_type' => 'percentage',
                'discount_value' => 50, // Diskon 50% untuk item Y

                'product_id' => null,
                'min_quantity' => null,
                'is_cumulative' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // ============================================================
            // SKENARIO 4: Diskon Kategori
            // Logic: Semua item di Kategori ID 1 diskon Rp 2.000
            // ============================================================
            [
                'business_id' => $businessId,
                'name' => 'DISKON_KATEGORI_KOPI',
                'priority' => 5,
                'is_active' => true,
                'applicable_for' => 'pos',

                // Tipe & JSON Kondisi
                'type' => 'category_discount',
                'condition_value' => json_encode([
                    'category_id' => 1 // ID Kategori target
                ]),

                // Reward
                'discount_type' => 'fixed_amount',
                'discount_value' => 2000, // Potongan 2rb

                'product_id' => null,
                'min_quantity' => null,
                'is_cumulative' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('discount_rules')->insert($rules);
    }
}
