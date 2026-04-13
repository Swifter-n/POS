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
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_id')->constrained(); // Menunjuk ke batch spesifik
            $table->foreignId('product_id')->constrained();
            $table->integer('system_stock')->comment('Stok sistem saat di-snapshot (dalam Base UoM)');
            $table->integer('final_counted_stock')->nullable()->comment('Stok final setelah divalidasi (dalam Base UoM)');
            $table->integer('variance')->default(0)->comment('Selisih, akan dihitung oleh accessor di model');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
    }
};
