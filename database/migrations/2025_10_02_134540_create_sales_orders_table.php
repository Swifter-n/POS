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
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('so_number')->unique();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('salesman_id')->comment('PIC Salesman dari tim')->constrained('users');
            $table->string('customer_po_number')->nullable()->comment('Nomor PO dari customer');
            $table->date('order_date');
            $table->string('status')->default('draft');
            $table->string('payment_type')->default('cash')->comment('cash, credit');
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('total_discount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
