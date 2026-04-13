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
        Schema::table('purchase_order_items', function (Blueprint $table) {
             $table->decimal('purchase_price', 15, 2)->after('quantity')->nullable()->comment('Harga beli aktual per unit dari supplier');
            // Menambahkan sub_total (Qty * Price)
            $table->decimal('sub_total', 15, 2)->after('purchase_price')->nullable()->comment('Sub-total per baris item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            //
        });
    }
};
