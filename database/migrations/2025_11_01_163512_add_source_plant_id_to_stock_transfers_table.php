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
        Schema::table('stock_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_transfers', 'plant_id')) {
                $table->foreignId('plant_id')->nullable()->after('business_id')->constrained('plants')->nullOnDelete();
            }
            if (!Schema::hasColumn('stock_transfers', 'transfer_type')) {
                $table->string('transfer_type')->nullable()->after('status');
            }

            // ==========================================================
            // --- INI ADALAH KOLOM YANG HILANG (PENYEBAB BUG) ---
            // ==========================================================

            // Kolom untuk STO Eksternal (Sumber)
            if (!Schema::hasColumn('stock_transfers', 'source_plant_id')) {
                $table->foreignId('source_plant_id')
                      ->nullable()
                      ->after('plant_id')
                      ->constrained('plants')
                      ->nullOnDelete();
            }

            // Kolom untuk STO Eksternal (Tujuan Plant)
            if (!Schema::hasColumn('stock_transfers', 'destination_plant_id')) {
                $table->foreignId('destination_plant_id')
                      ->nullable()
                      ->after('source_plant_id')
                      ->constrained('plants')
                      ->nullOnDelete();
            }

            // Kolom untuk STO Eksternal (Tujuan Outlet)
            if (!Schema::hasColumn('stock_transfers', 'destination_outlet_id')) {
                $table->foreignId('destination_outlet_id')
                      ->nullable()
                      ->after('destination_plant_id')
                      ->constrained('outlets')
                      ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            try {
                 $table->dropForeign(['source_plant_id']);
                 $table->dropForeign(['destination_plant_id']);
                 $table->dropForeign(['destination_outlet_id']);
             } catch (\Exception $e) { /* Abaikan */ }

            if (Schema::hasColumn('stock_transfers', 'source_plant_id')) {
                 $table->dropColumn('source_plant_id');
            }
            if (Schema::hasColumn('stock_transfers', 'destination_plant_id')) {
                 $table->dropColumn('destination_plant_id');
            }
             if (Schema::hasColumn('stock_transfers', 'destination_outlet_id')) {
                 $table->dropColumn('destination_outlet_id');
            }
        });
    }
};
