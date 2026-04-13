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
        Schema::table('priority_levels', function (Blueprint $table) {
            $table->string('scope')->default('sales_order')->after('name');
            // 2. Syarat Poin (Khusus POS)
            $table->integer('min_points')->default(0)->after('min_spend');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('priority_levels', function (Blueprint $table) {
            $table->dropColumn(['scope', 'min_points']);
        });
    }
};
