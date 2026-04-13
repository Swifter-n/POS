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
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders');
        });

        //  Schema::create('purchase_returns', function (Blueprint $table) {
        //     $table->foreignId('purchase_order_id')->nullable()->constrained();
        // });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });

        // Schema::table('purchase_returns', function (Blueprint $table) {
        //     $table->dropForeign(['purchase_order_id']);
        //     $table->dropColumn('purchase_order_id');
        // });
    }
};
