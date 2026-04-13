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
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['fleet_id']);
            $table->dropColumn(['fleet_id', 'driver_name', 'vehicle_plate_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable()->constrained('fleets');
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate_number')->nullable();
        });
    }
};
