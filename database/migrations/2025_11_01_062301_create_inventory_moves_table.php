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
        Schema::create('inventory_moves', function (Blueprint $table) {
            $table->id();
            $table->string('move_number')->unique(); // Misal: "MV-202511-0001"
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // Konteks (opsional tapi sangat membantu)
            $table->foreignId('plant_id')->nullable()->constrained('plants')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Item yang dipindah
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('inventory_id')->constrained('inventories'); // Batch/SLED spesifik

            // Lokasi
            $table->foreignId('source_location_id')->constrained('locations');
            $table->foreignId('destination_location_id')->constrained('locations');

            // Kuantitas (selalu dalam Base UoM)
            $table->decimal('quantity_base', 15, 4);

            $table->string('reason')->nullable(); // Alasan (misal: "Koreksi Put-Away", "Replenishment")
            $table->foreignId('moved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at'); // Kapan dipindahkan
            $table->string('status')->default('completed'); // Ad-hoc move biasanya langsung 'completed'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_moves');
    }
};
