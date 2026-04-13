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
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Kolom untuk menyimpan data sebelum & sesudah, tipe JSON agar fleksibel
            $table->json('old_value')->nullable()->after('notes');
            $table->json('new_value')->nullable()->after('old_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['old_value', 'new_value']);
        });
    }
};
