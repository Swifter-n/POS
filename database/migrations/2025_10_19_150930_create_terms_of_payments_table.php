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
        Schema::create('terms_of_payments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Contoh: Net 30, Net 60, COD
            $table->integer('days')->default(0); // Jumlah hari jatuh tempo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms_of_payments');
    }
};
