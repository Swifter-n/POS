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
        Schema::table('putaway_rules', function (Blueprint $table) {
        // Hapus kolom lama yang membingungkan
        $table->dropColumn('product_type');

        // Tambahkan Kolom Kriteria Baru (Nullable = Berlaku untuk semua)
        // 1. Match dengan products.storage_condition (FAST, COLD, DRY)
        $table->string('criteria_storage_condition')->nullable()->after('business_id');

        // 2. Match dengan products.product_type (finished_good, raw_material)
        $table->string('criteria_product_type')->nullable()->after('criteria_storage_condition');

        // 3. Match dengan products.category_id (tetap ada)
        // category_id sudah ada, biarkan saja atau rename jadi criteria_category_id agar konsisten
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
