<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_returns', function (Blueprint $table) {
             $table->foreignId('plant_id')
                  ->nullable()
                  ->after('business_id') // Sesuaikan 'after' jika perlu
                  ->constrained('plants')
                  ->nullOnDelete();

            // Tambahkan warehouse_id (nullable, constrained)
            // Ini adalah gudang utama tempat return diproses
            $table->foreignId('warehouse_id')
                  ->nullable()
                  ->after('plant_id')
                  ->constrained('warehouses')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_returns', function (Blueprint $table) {
            try {
                 $table->dropForeign(['plant_id']);
                 $table->dropForeign(['warehouse_id']);
             } catch (\Exception $e) { /* Abaikan */ }

            $table->dropColumn('plant_id');
            $table->dropColumn('warehouse_id');
        });
    }
};
