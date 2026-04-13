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
        Schema::table('shipment_sourceables', function (Blueprint $table) {
            // 1. Hapus constraint lama yang terlalu ketat
            // Nama index ini saya ambil dari pesan error Anda: "one_sourceable_to_one_shipment_unique"
            // Jika error saat rollback nanti, pastikan nama indexnya benar di database Anda.
            $table->dropUnique('one_sourceable_to_one_shipment_unique');

            // 2. (Opsional) Buat constraint baru yang lebih logis
            // Mencegah PO yang sama dimasukkan 2 kali ke dalam SHIPMENT YANG SAMA (tapi boleh di shipment beda)
            $table->unique(
                ['shipment_id', 'sourceable_id', 'sourceable_type'],
                'unique_source_per_shipment'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipment_sourceables', function (Blueprint $table) {
            // Kembalikan ke aturan ketat (1 PO = 1 Shipment)
            $table->dropUnique('unique_source_per_shipment');

            $table->unique(
                ['sourceable_id', 'sourceable_type'],
                'one_sourceable_to_one_shipment_unique'
            );
        });
    }
};
