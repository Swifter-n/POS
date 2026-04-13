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
        Schema::table('locations', function (Blueprint $table) {
        $table->string('ownership_type')->default('owned')->after('is_sellable');
        // Jika konsinyasi, ini menunjuk ke supplier pemilik barang
        $table->foreignId('supplier_id')->nullable()->after('ownership_type')->constrained('vendors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {

        });
    }
};
