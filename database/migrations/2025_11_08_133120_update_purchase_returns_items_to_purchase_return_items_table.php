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
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->change();

            // Tambahkan kolom 'uom'
            $table->string('uom')->after('quantity');

            // Tambahkan kolom 'quantity_base_uom'
            $table->decimal('quantity_base_uom', 15, 4)->after('uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropColumn('uom');
            $table->dropColumn('quantity_base_uom');
            // Kembalikan ke integer
            $table->integer('quantity')->change();
        });
    }
};
