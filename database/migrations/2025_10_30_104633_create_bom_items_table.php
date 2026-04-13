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
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();

            // Produk KOMPONEN (input)
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // ==========================================================
            // --- INI ADALAH IMPLEMENTASI IDE ANDA ---
            // ==========================================================
            $table->string('usage_type')
                  ->default('RAW_MATERIAL')
                  ->comment('RAW_MATERIAL, RAW_MATERIAL_STORE, BY_PRODUCT, etc.');
            // ==========================================================

            $table->decimal('quantity', 15, 4); // Kuantitas komponen
            $table->string('uom'); // Satuan komponen

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
