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
        Schema::table('stock_counts', function (Blueprint $table) {
             // Tambahkan plant_id (nullable, constrained)
            // Ditempatkan setelah business_id (jika ada) atau countable_id
            $table->foreignId('plant_id')
                  ->nullable()
                  ->after('business_id') // Sesuaikan 'after' jika perlu
                  ->constrained('plants')
                  ->nullOnDelete(); // Jika Plant dihapus, set ID ke null

            // Tambahkan zone_id (nullable, constrained)
            $table->foreignId('zone_id')
                  ->nullable()
                  ->after('plant_id')
                  ->constrained('zones')
                  ->nullOnDelete(); // Jika Zone dihapus, set ID ke null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
              try {
                 $table->dropForeign(['plant_id']);
                 $table->dropForeign(['zone_id']);
             } catch (\Exception $e) {
                 // Abaikan error jika foreign key tidak ada
             }

            // Drop kolom
            $table->dropColumn('plant_id');
            $table->dropColumn('zone_id');
        });
    }
};
