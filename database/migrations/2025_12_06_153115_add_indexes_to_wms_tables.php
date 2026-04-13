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
        Schema::table('inventories', function (Blueprint $table) {
        // Index untuk mempercepat pencarian stok saat Putaway/Picking
        $table->index(['location_id', 'product_id', 'avail_stock']);
        $table->index(['product_id', 'batch']); // Untuk pencarian batch spesifik
        $table->index('sled'); // Untuk sorting FEFO
    });

    Schema::table('put_away_entries', function (Blueprint $table) {
        $table->index(['stock_transfer_id']);
    });

    Schema::table('locations', function (Blueprint $table) {
         $table->index(['zone_id', 'status', 'is_sellable']); // Mempercepat PutawayStrategyService
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_tables', function (Blueprint $table) {
            //
        });
    }
};
