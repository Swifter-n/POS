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
            $table->foreignId('plant_id')->nullable()->after('business_id')->constrained('plants')->nullOnDelete();
            if (!Schema::hasColumn('warehouses', 'type')) {
                 $table->string('type')->nullable()->after('name')->comment('RAW_MATERIAL, FINISHED_GOOD, COLD_STORAGE, DISTRIBUTION, MAIN, OTHER');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            try {
                 $table->dropForeign(['plant_id']);
             } catch (\Exception $e) { /* Abaikan jika foreign key tidak ada */ }
             $table->dropColumn('plant_id');

             if (Schema::hasColumn('warehouses', 'type')) {
                 $table->dropColumn('type');
             }
        });
    }
};
