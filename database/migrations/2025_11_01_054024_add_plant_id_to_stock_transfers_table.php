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
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('plant_id')
                  ->nullable()
                  ->after('business_id') // Sesuaikan 'after' jika perlu
                  ->constrained('plants')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            try {
                 $table->dropForeign(['plant_id']);
             } catch (\Exception $e) { /* Abaikan */ }

            $table->dropColumn('plant_id');
        });
    }
};
