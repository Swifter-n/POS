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
        Schema::table('outlets', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->constrained('areas')->after('business_id');
            $table->foreignId('supplying_warehouse_id')->nullable()->constrained('warehouses')->after('area_id');
            $table->foreignId('village_id')->nullable()->constrained('villages')->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            //
        });
    }
};
