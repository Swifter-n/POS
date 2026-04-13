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
        // Presisi 15 digit, dengan 4 angka di belakang koma (misal: 100.1234 gram

        Schema::table('inventories', function (Blueprint $table) {
            $precision = 15;
            $scale = 4;
            $table->decimal('avail_stock', $precision, $scale)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->integer('avail_stock')->default(0)->change();
        });
    }
};
