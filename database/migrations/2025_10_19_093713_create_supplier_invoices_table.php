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
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('supplier_id')->constrained('vendors');

            // Relasi polimorfik ke dokumen pemicu (bisa SalesOrder, Order, dll)
            $table->morphs('sourceable');

            $table->date('invoice_date');
            $table->decimal('total_amount', 15, 2);
            $table->string('status')->default('unpaid'); // unpaid, paid
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
