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
        Schema::create('product_uoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('uom_name')->comment('Contoh: PCS, PET, CRT');
            $table->string('barcode')->unique()->nullable();
            $table->unsignedInteger('conversion_rate')->comment('Berapa PCS dalam UoM ini. Contoh: PCS=1, PET=12, CRT=24');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_uoms');
    }
};
