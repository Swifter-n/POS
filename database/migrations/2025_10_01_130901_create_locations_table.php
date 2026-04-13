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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            // // Kolom ini akan menunjuk ke ID di tabel 'locations' itu sendiri.
            // $table->foreignId('parent_id')->nullable()->comment('Induk dari lokasi ini')->constrained('locations')->onDelete('cascade');
            // $table->string('name')->comment('Contoh: Area Kering, Rak A-01, Bin-01');
            // $table->string('type')->nullable()->comment('Contoh: AREA, RACK, BIN, PALLET');
            // $table->string('zone')->nullable()->comment('Zona lokasi, misal: A=Fast Moving, B=Cold Storage');
            // $table->decimal('max_capacity', 10, 2)->nullable();
            // $table->string('capacity_unit')->nullable()->comment('Satuan kapasitas, misal: KG, CBM (Cubic Meter), Pallet');
            // $table->string('barcode')->unique()->nullable();

            // Relasi polimorfik: Lokasi ini bisa dimiliki oleh Warehouse atau Outlet
            $table->morphs('locatable'); // Membuat `locatable_id` & `locatable_type`

            // Untuk hierarki (parent-child relationship)
            $table->foreignId('parent_id')->nullable()->constrained('locations')->onDelete('cascade');
            $table->string('name'); // Cth: Gudang Pusat, Area Picking, Rak A-01, Bin A-01-01
            $table->string('code')->nullable(); // Cth: AFS, QI, BLK. Dibuat nullable agar tidak wajib untuk semua level.
            $table->string('barcode')->unique()->nullable(); // Untuk scanning di Bin/Pallet
            // Tipe lokasi untuk membedakan levelnya
            $table->enum('type', ['WAREHOUSE', 'OUTLET', 'AREA', 'RACK', 'BIN', 'PALLET']);
            // ield kapasitas
            $table->decimal('max_capacity', 10, 2)->nullable()->after('is_sellable');
            $table->string('capacity_unit')->nullable()->after('max_capacity'); // Cth: KG, CBM, PALLET
            // Properti penting yang diwarisi dari konsep SLoc
            $table->boolean('is_sellable')->default(false)->comment('Apakah stok di lokasi ini bisa dijual?');
            // Index unik agar kode lokasi tidak duplikat di dalam satu induk
            $table->unique(['locatable_id', 'locatable_type', 'code']);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
