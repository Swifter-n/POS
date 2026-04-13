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
        Schema::create('shipment_sourceables', function (Blueprint $table) {
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();

            // Relasi Polimorfik ke SO atau STO
            $table->morphs('sourceable'); // Membuat sourceable_id (bigint) & sourceable_type (string)

            // Tambahkan business_id (untuk multi-tenancy & GANTI agar TIDAK NULL)
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // Kunci Utama (Primary Key)
            $table->primary(['shipment_id', 'sourceable_id', 'sourceable_type'], 'shipment_sourceable_primary_key');

            // =================================================================
            // --- KUNCI UNTUK ATURAN BISNIS ANDA ---
            // (1 SO/STO tidak bisa 2 DO)
            // =================================================================
            // Ini memastikan bahwa 1 SO/STO hanya bisa muncul SATU KALI di seluruh tabel ini,
            // sehingga mencegah 1 SO/STO terhubung ke 2 Shipment yang berbeda.
            $table->unique(['sourceable_id', 'sourceable_type'], 'one_sourceable_to_one_shipment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_sourceables');
    }
};
