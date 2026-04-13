<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
    {
        // 1. Update Purchase Orders: Tambah Metode Pengiriman
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Opsi: 'supplier_delivery' (Vendor Antar) atau 'self_pickup' (Kita Jemput)
            $table->string('shipping_method')->default('supplier_delivery')->after('po_type');
        });

        // 2. Update Purchase Order Items: Tracking Parsial
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Menghitung berapa yang sudah diterima/di-GR
            $table->integer('quantity_received')->default(0)->after('quantity');
        });

        // 3. Update Shipping Rates: Mencegah Data Ganda (Unique Index)
        Schema::table('shipping_rates', function (Blueprint $table) {
            // Pastikan kombinasi Business, Asal, Tujuan, Vendor, dan Tipe Kendaraan itu unik
            $table->unique(
                ['business_id', 'from_area_id', 'to_area_id', 'vendor_id', 'fleet_type'],
                'unique_shipping_rate_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_method');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('quantity_received');
        });
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropUnique('unique_shipping_rate_index');
        });
    }
};
