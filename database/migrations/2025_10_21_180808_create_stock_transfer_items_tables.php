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
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();

            $table->integer('quantity')->comment('Kuantitas yang diminta');
            $table->string('uom');
            $table->integer('quantity_available')->nullable()->comment('Stok tersedia (dalam Base UoM)');

            // === KOLOM BARU UNTUK KUANTITAS AKTUAL ===
            $table->integer('quantity_picked')->nullable()->comment('Kuantitas aktual yang diambil (dalam UoM)');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
