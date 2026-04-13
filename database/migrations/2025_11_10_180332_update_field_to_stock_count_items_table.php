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
            $precision = 15;
            $scale = 4;
            $table->decimal('system_stock', $precision, $scale)->change();
            $table->decimal('final_counted_stock', $precision, $scale)->nullable()->change();

            // Hapus kolom 'variance' (lebih baik dihitung via accessor)
            if (Schema::hasColumn('stock_count_items', 'variance')) {
                $table->dropColumn('variance');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->integer('system_stock')->change();
            $table->integer('final_counted_stock')->nullable()->change();
            $table->integer('variance')->default(0);
        });
    }
};
