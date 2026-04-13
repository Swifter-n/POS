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
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->foreignId('plant_id')
                  ->nullable()
                  ->after('business_id') // Posisikan setelah business_id
                  ->constrained('plants')
                  ->nullOnDelete();

            // Tambahkan kolom warehouse_id (bisa null, terutama untuk Outlet)
            $table->foreignId('warehouse_id')
                  ->nullable()
                  ->after('plant_id') // Posisikan setelah plant_id
                  ->constrained('warehouses')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropForeign(['plant_id']);
            $table->dropColumn('plant_id');
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
