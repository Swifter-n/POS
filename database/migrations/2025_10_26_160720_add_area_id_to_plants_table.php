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
        Schema::table('plants', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('business_id')->constrained('areas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            try {
                $table->dropForeign(['area_id']);
            } catch (\Exception $e) { /* Abaikan jika foreign key tidak ada */ }
            $table->dropColumn('area_id');
        });
    }
};
