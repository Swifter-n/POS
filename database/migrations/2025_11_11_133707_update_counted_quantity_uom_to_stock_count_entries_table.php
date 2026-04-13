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

        Schema::table('stock_count_entries', function (Blueprint $table) {
            $precision = 15;
            $scale = 4;
            $table->decimal('counted_quantity', $precision, $scale)->change();

            // Tambahkan kolom UoM untuk hitungan per tim
            $table->string('counted_uom', 50)->nullable()->after('counted_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_count_entries', function (Blueprint $table) {
            $table->integer('counted_quantity')->change();
            $table->dropColumn('counted_uom');
        });
    }
};
