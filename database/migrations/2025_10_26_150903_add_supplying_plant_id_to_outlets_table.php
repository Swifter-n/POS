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
        Schema::table('outlets', function (Blueprint $table) {
            $table->foreignId('supplying_plant_id')->nullable()->after('business_id')->constrained('plants')->nullOnDelete();

            if (Schema::hasColumn('outlets', 'supplying_warehouse_id')) {
                 // Drop foreign key constraint first if it exists
                 // Nama constraint mungkin berbeda, cek skema DB Anda
                 try {
                     $table->dropForeign(['supplying_warehouse_id']);
                 } catch (\Exception $e) {
                     // Abaikan jika foreign key tidak ada
                 }
                 $table->dropColumn('supplying_warehouse_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->foreignId('supplying_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

             // Hapus kolom baru
             $table->dropForeign(['supplying_plant_id']);
             $table->dropColumn('supplying_plant_id');
        });
    }
};
