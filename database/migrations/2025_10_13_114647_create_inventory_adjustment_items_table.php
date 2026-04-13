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
        Schema::create('inventory_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_adjustment_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity_before');
            $table->integer('quantity_change')->comment('Positif jika menambah, negatif jika mengurangi');
            $table->integer('quantity_after');
            $table->string('reason_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_items');
    }
};
