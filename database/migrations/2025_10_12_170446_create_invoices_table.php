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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('sales_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('business_id')->constrained();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('sub_total', 15, 2);
            $table->decimal('total_discount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->string('status')->default('unpaid'); // unpaid, paid, overdue, cancelled
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
