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
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->foreignId('priority_level_id')->nullable()->after('min_quantity')->constrained('priority_levels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            //
        });
    }
};
