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
        Schema::create('goods_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_return_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();

            // Menunjuk ke batch spesifik di inventaris yang diretur untuk keterlacakan
            $table->foreignId('inventory_id')->nullable()->constrained();

            $table->unsignedInteger('quantity')->comment('Kuantitas dalam Base UoM');
            $table->string('reason_code')->comment('cth: DAMAGED, NEAR_EXPIRY, OVERSTOCK');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_return_items');
    }
};
