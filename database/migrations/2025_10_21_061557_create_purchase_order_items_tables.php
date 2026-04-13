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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');

            // Kuantitas dalam satuan yang dipesan
            $table->integer('quantity');
            $table->string('uom');

            // Harga dalam satuan yang dipesan
            $table->decimal('price_per_item', 15, 2);
            $table->decimal('total_price', 15, 2);

            // Kolom ini akan diisi oleh proses 'Check Stock'
            $table->integer('quantity_available')->nullable()->comment('Stok tersedia (dalam Base UoM)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items_tables');
    }
};
