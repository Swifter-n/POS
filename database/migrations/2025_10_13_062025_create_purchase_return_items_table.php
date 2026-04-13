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
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
                $table->foreignId('purchase_return_id')->constrained()->onDelete('cascade');
                // Menunjuk ke batch spesifik yang ada di stok rusak
                $table->foreignId('inventory_id')->constrained();
                $table->foreignId('product_id')->constrained();
                $table->integer('quantity'); // Dalam Base UoM
                $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
