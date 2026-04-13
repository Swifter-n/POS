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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('supplier_id')->constrained('vendors'); // Menunjuk ke tabel vendors/suppliers
            //$table->foreignId('purchase_order_id')->nullable()->constrained();
            $table->foreignId('warehouse_id')->constrained(); // Gudang asal retur
            $table->string('status')->default('draft'); // draft, approved, shipped, completed
            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
