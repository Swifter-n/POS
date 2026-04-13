<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RewardCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $businessId = 1; // Sesuaikan dengan Business ID Anda
        $now = Carbon::now();

        // Referensi ID Discount Rule (Sesuai data testing Anda sebelumnya)
        // Pastikan ID ini ada di tabel discount_rules.
        // ID 8 = BOGO_KOPI_SUSU
        // ID 5 = Potongan Rp 5.000 (All)

        $bogoRuleId = 8;
        $discountRuleId = 5;

        // Hapus data lama jika ada (opsional, untuk menghindari duplikat saat seeding ulang)
        // DB::table('reward_catalogs')->truncate();

        DB::table('reward_catalogs')->insert([
            [
                'business_id' => $businessId,
                'name' => 'Voucher BOGO Kopi Susu',
                'description' => 'Tukarkan 100 Poin untuk mendapatkan Buy 1 Get 1 Kopi Susu! Berlaku untuk dine-in dan take away.',
                'image' => null, // Bisa diisi URL gambar icon voucher
                'points_required' => 100, // Harga: 100 Poin
                'discount_rule_id' => $bogoRuleId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_id' => $businessId,
                'name' => 'Potongan Rp 5.000',
                'description' => 'Tukarkan 50 Poin untuk potongan harga Rp 5.000. Berlaku untuk semua produk.',
                'image' => null,
                'points_required' => 50, // Harga: 50 Poin
                'discount_rule_id' => $discountRuleId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'business_id' => $businessId,
                'name' => 'Voucher Spesial (Sample)',
                'description' => 'Contoh reward mahal untuk pelanggan setia.',
                'image' => null,
                'points_required' => 500,
                'discount_rule_id' => null, // Bisa null jika logic reward ditangani manual/fisik
                'is_active' => false, // Non-aktif
                'created_at' => $now,
                'updated_at' => $now,
            ]
        ]);
    }
}
