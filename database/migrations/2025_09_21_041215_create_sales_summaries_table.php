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
        Schema::create('sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                  ->constrained('businesses')
                  ->onDelete('cascade');
            $table->date('date');
            $table->integer('total_sales');
            $table->integer('total_tax');
            $table->integer('total_discount');
            $table->integer('total_profit');
            $table->integer('total_quantity');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_summaries');
    }
};
