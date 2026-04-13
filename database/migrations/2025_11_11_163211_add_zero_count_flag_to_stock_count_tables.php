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
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->boolean('is_zero_count')->default(false)->nullable()->after('final_counted_uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropColumn('is_zero_count');
        });
    }
};
