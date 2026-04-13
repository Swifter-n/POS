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
        Schema::table('users', function (Blueprint $table) {
        // Hapus foreign key dan kolom 'outlet_id'
        $table->dropForeign(['outlet_id']);
        $table->dropColumn('outlet_id');

        // Tambahkan kolom polimorfik 'locationable'
        $table->nullableMorphs('locationable', 'user_location');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
