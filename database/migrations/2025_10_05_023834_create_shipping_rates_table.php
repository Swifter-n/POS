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
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            // Vendor bisa null jika tarif ini untuk armada internal
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            // Tipe kendaraan, misal: 'Motor', 'Truk Engkel', dll.
            $table->string('fleet_type')->nullable();
            $table->foreignId('from_area_id')->constrained('areas'); // Area asal
            $table->foreignId('to_area_id')->constrained('areas');   // Area tujuan
            $table->decimal('cost', 15, 2);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
