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
        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'area_id')) {
                 // Drop foreign key constraint first
                 try {
                     $table->dropForeign(['area_id']);
                 } catch (\Exception $e) { /* Abaikan jika foreign key tidak ada */ }
                 $table->dropColumn('area_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
        });
    }
};
