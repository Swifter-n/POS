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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->string('po_number')->unique();

            // Relasi ke master data
            $table->foreignId('vendor_id')->constrained('vendors'); // Nama diubah dari supplier_id untuk konsistensi
            $table->foreignId('warehouse_id')->constrained('warehouses'); // Gudang tujuan

            // Kolom Klasifikasi PO (Konsep Baru)
            $table->string('po_type'); // Cth: raw_material, asset, non_asset
            $table->string('price_type'); // Cth: standard, special, consignment

            // Informasi Tanggal
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();

            // Informasi Finansial (dihitung dari item)
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('total_discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            // Status Alur Kerja
            $table->string('status')->default('draft'); // draft, approved, partially_received, fully_received, cancelled

            // Jejak Audit Pengguna
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders_tables');
    }
};
