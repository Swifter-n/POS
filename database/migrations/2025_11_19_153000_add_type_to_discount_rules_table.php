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
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->json('condition_value')->nullable();
            // Menambahkan kolom untuk flag cumulative
            $table->boolean('is_cumulative')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->dropColumn(['condition_value', 'is_cumulative']);
        });
    }
};
