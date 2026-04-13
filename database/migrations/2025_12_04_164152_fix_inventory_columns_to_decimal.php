<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Menggunakan RAW SQL untuk PostgreSQL agar proses casting aman
        // Mengubah tipe data menjadi NUMERIC(15, 4)

        // 1. Tabel inventories (Stok Master)
        DB::statement('ALTER TABLE inventories ALTER COLUMN avail_stock TYPE numeric(15, 4)');

        // 2. Tabel inventory_movements (Log Pergerakan)
        DB::statement('ALTER TABLE inventory_movements ALTER COLUMN quantity_change TYPE numeric(15, 4)');
        DB::statement('ALTER TABLE inventory_movements ALTER COLUMN stock_after_move TYPE numeric(15, 4)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kembalikan ke Integer (Warning: Data desimal akan terpotong/bulat)
        DB::statement('ALTER TABLE inventories ALTER COLUMN avail_stock TYPE integer USING ROUND(avail_stock)');

        DB::statement('ALTER TABLE inventory_movements ALTER COLUMN quantity_change TYPE integer USING ROUND(quantity_change)');
        DB::statement('ALTER TABLE inventory_movements ALTER COLUMN stock_after_move TYPE integer USING ROUND(stock_after_move)');
    }
};
