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
        Schema::create('picking_list_shipments', function (Blueprint $table) {
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('picking_list_id')->constrained('picking_lists')->cascadeOnDelete();

            // ==========================================================
            // --- TAMBAHAN BARU: business_id untuk Multi-Tenancy ---
            // ==========================================================
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // ==========================================================

            // Kunci Utama (Update: tambahkan business_id agar unik per bisnis)
            $table->primary(['shipment_id', 'picking_list_id', 'business_id'], 'picking_list_shipment_primary_key');

            // ==========================================================
            // --- KUNCI: 1 Picking List HANYA bisa ada di 1 Shipment (PER BISNIS) ---
            // ==========================================================
            $table->unique(['picking_list_id', 'business_id'], 'one_picking_list_per_business_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('picking_list_shipments');
    }
};
