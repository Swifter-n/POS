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
        Schema::table('goods_returns', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                  ->nullable()
                  ->after('business_id') // (Opsional: atur posisi)
                  ->constrained('sales_orders')
                  ->nullOnDelete();

            $table->foreignId('customer_id')
                  ->nullable()
                  ->after('sales_order_id') // (Opsional: atur posisi)
                  ->constrained('customers')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_returns', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropForeign(['customer_id']);
            $table->dropColumn(['sales_order_id', 'customer_id']);
        });
    }
};
