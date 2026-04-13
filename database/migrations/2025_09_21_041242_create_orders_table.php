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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('outlet_id')
                  ->constrained('outlets')
                  ->onDelete('cascade');
            $table->decimal('sub_total', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->integer('total_items');
            $table->decimal('tax', 10, 2);
            $table->decimal('discount', 10, 2);
            $table->string('payment_method');
            $table->string('table_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->foreignId('promo_code')->nullable()
                  ->constrained('promos')
                  ->onDelete('cascade');
            $table->string('status')->default('Pending');
            $table->foreignId('cashier_id')->nullable()
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
