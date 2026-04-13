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
        Schema::table('products', function (Blueprint $table) {
            // Berat per satuan dasar (misal: per PCS) dalam KG
        $table->decimal('weight_kg', 8, 2)->default(0)->after('color');
        // Volume per satuan dasar dalam CBM (meter kubik)
        $table->decimal('volume_cbm', 8, 4)->default(0)->after('weight_kg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};
