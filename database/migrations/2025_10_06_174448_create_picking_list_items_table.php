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
        Schema::create('picking_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('picking_list_id')->constrained('picking_lists')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('total_quantity_to_pick', 8, 2);
            $table->string('uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('picking_list_items');
    }
};
