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
        Schema::create('put_away_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers'); // Header Task
            $table->foreignId('stock_transfer_item_id')->constrained('stock_transfer_items'); // Item Line (e.g., 2400 pcs)
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('destination_location_id')->constrained('locations'); // Tujuan (e.g., PALLET-01)
            $table->decimal('quantity_moved', 15, 5); // Qty Base UoM (e.g., 1500)
            $table->foreignId('user_id')->constrained('users'); // Siapa yang melakukan
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('put_away_entries');
    }
};
