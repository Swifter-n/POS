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
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('debit_note_number')->unique();
            $table->foreignId('purchase_return_id')->constrained();
            $table->foreignId('supplier_id')->constrained('vendors');
            $table->foreignId('business_id')->constrained();
            $table->date('note_date');
            $table->decimal('total_amount', 15, 2);
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
