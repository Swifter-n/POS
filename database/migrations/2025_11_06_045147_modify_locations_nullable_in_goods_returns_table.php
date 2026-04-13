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
        Schema::table('goods_returns', function (Blueprint $table) {
            $table->foreignId('source_location_id')->nullable()->change();
            $table->foreignId('destination_location_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_returns', function (Blueprint $table) {
            $table->foreignId('source_location_id')->nullable(false)->change();
            $table->foreignId('destination_location_id')->nullable(false)->change();
        });
    }
};
