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
        Schema::table('shipment_routes', function (Blueprint $table) {
            $table->foreignId('source_plant_id')->nullable()->after('id')->constrained('plants')->nullOnDelete();

             // 2. Hapus kolom warehouse_id
            if (Schema::hasColumn('shipment_routes', 'source_warehouse_id')) {
                 try {
                     $table->dropForeign(['source_warehouse_id']);
                 } catch (\Exception $e) { /* Abaikan */ }
                 $table->dropColumn('source_warehouse_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipment_routes', function (Blueprint $table) {
             // 1. Tambahkan kembali kolom warehouse_id
             $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

             // 2. Hapus kolom plant_id
             try {
                 $table->dropForeign(['source_plant_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('source_plant_id');
        });
    }
};
