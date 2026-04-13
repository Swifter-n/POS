<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    // Langkah 1: Isi nilai NULL dengan 0 terlebih dahulu
    DB::table('picking_list_items')
        ->whereNull('quantity_picked')
        ->update(['quantity_picked' => 0]);

    // Langkah 2: Ubah tipe data kolom
    Schema::table('picking_list_items', function (Blueprint $table) {
        $table->decimal('quantity_picked', 15, 4)
            ->default(0)
            ->nullable(false)
            ->change();
    });
}

public function down(): void
{
    Schema::table('picking_list_items', function (Blueprint $table) {
        $table->integer('quantity_picked')->nullable()->change();
    });
}

};
