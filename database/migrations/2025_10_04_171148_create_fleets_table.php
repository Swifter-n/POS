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
        Schema::create('fleets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->string('ownership')->default('internal');// internal, vendor
            $table->string('vehicle_name'); // Contoh: Truk Engkel 01
            $table->string('plate_number')->unique();
            $table->string('type')->comment('Truk, Mobil Box, Motor');
            $table->enum('status', ['available', 'in_use', 'maintenance'])->default('available');
            $table->decimal('max_load_kg', 8, 2)->nullable();
            $table->decimal('max_volume_cbm', 8, 2)->nullable()->comment('Kapasitas volume kubik');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleets');
    }
};
