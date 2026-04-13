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
        Schema::table('purchase_orders', function (Blueprint $table) {
             $table->foreignId('plant_id')->nullable()->after('business_id')->constrained('plants')->nullOnDelete();
            // 2. Hapus kolom warehouse_id
            if (Schema::hasColumn('purchase_orders', 'warehouse_id')) {
                 // Drop foreign key constraint first
                 try {
                     $table->dropForeign(['warehouse_id']);
                 } catch (\Exception $e) { /* Abaikan jika foreign key tidak ada */ }
                 $table->dropColumn('warehouse_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

             // 2. Hapus kolom plant_id
             try {
                 $table->dropForeign(['plant_id']);
             } catch (\Exception $e) { /* Abaikan jika foreign key tidak ada */ }
             $table->dropColumn('plant_id');
        });
    }
};
