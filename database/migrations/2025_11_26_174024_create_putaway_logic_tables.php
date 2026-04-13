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
        Schema::create('putaway_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');

            // Kriteria Pencocokan (Match Product)
            $table->string('product_type')->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained('categories'); // Opsional: Filter by Category

            // Target (Match Zone)
            $table->foreignId('target_zone_id')->constrained('zones'); // Zone Tujuan (FG, RM, dll)

            // Logika Urutan
            $table->integer('priority')->default(1); // 1 = Prioritas Utama, 2 = Cadangan
            $table->string('strategy')->default('empty_bin'); // 'empty_bin' (Cari kosong), 'mixed' (Campur boleh)

            $table->timestamps();
        });

        // 2. Update Locations (Kapasitas Bin)
        Schema::table('locations', function (Blueprint $table) {
            // Kapasitas bisa dalam KG atau Jumlah Pallet (Handling Unit)
            $table->integer('max_pallets')->default(1)->comment('Kapasitas maksimal pallet/tumpukan');
            $table->integer('current_pallets')->default(0)->comment('Jumlah pallet saat ini');

            // Sequence untuk mengurutkan jalan si Picker (Pathfinding sederhana)
            // Misal: A-01-01 sequence 1, A-01-02 sequence 2
            $table->integer('picking_sequence')->default(0);
        });

        // 3. Update Stock Transfer Items (Menyimpan Saran Lokasi)
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            // Agar mobile app tahu harus ke mana
            $table->foreignId('suggested_location_id')->nullable()->constrained('locations');
        });

        Schema::table('products', function (Blueprint $table) {
            // 1. Preferred/Target Zone (Direct Assignment)
            // Menggantikan ide 'product_type' string sebelumnya
            $table->foreignId('target_zone_id')
                  ->nullable()
                  ->comment('Zona prioritas utama untuk putaway')
                  ->constrained('zones')
                  ->nullOnDelete();

            // 2. Storage Condition (Tetap perlu untuk validasi kompatibilitas)
            // Misal: 'COLD', 'HAZARDOUS', 'DRY'
            // Ini berguna jika Target Zone penuh, sistem mencari fallback yang kondisinya cocok
            $table->string('storage_condition')->nullable()->after('target_zone_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('putaway_rules');
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['max_pallets', 'current_pallets', 'picking_sequence']);
        });
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('suggested_location_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['target_zone_id']);
            $table->dropColumn(['target_zone_id', 'storage_condition']);
        });
    }
};
