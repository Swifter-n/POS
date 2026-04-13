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
        Schema::create('picking_list_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('picking_list_item_id')->constrained('picking_list_items')->onDelete('cascade');
            // Sumber inventaris spesifik yang harus diambil
            $table->foreignId('inventory_id')->constrained('inventories');
            $table->decimal('quantity_to_pick_from_source', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('picking_list_item_sources');
    }
};
