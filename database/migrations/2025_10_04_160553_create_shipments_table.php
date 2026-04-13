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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_number')->unique()->comment('Nomor Surat Jalan / DO');
            $table->foreignId('business_id')->constrained('businesses');

            // Sumber pengiriman (polimorfik)
            $table->morphs('sourceable'); // Bisa dari StockTransfer atau SalesOrder

            $table->string('status'); // ready_to_ship, shipping, delivered

            // Detail Pengiriman
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate_number')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users');
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
