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
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // Produk JADI (output) dari BOM ini
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('name'); // Nama BOM (misal "Resep Espresso Cafe", "Resep Biji Sangrai Pabrik")
            $table->string('code')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            // Pastikan 1 produk jadi hanya punya 1 BOM aktif
            $table->unique(['business_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
