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
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreignId('plant_id')
                  ->after('business_id')
                  ->constrained('plants') // Asumsi tabel Anda bernama 'plants'
                  ->onDelete('restrict');
            $table->foreignId('created_by_user_id')
                  ->after('business_id')
                  ->constrained('users')
                  ->onDelete('restrict');

            $table->foreignId('approved_by_user_id')
                  ->nullable() // Boleh null
                  ->after('created_by_user_id')
                  ->constrained('users')
                  ->onDelete('set null'); // Jika user dihapus, set null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign(['plant_id']);
            $table->dropColumn('plant_id');
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
            $table->dropForeign(['approved_by_user_id']);
            $table->dropColumn('approved_by_user_id');
        });
    }
};
