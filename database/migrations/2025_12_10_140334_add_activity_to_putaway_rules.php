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
        Schema::table('putaway_rules', function (Blueprint $table) {
            $table->string('activity')->default('putaway')->after('business_id')
                  ->comment('Menentukan apakah rule ini untuk Putaway, Picking, atau Keduanya');

            // Index untuk mempercepat pencarian
            $table->index(['business_id', 'activity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('putaway_rules', function (Blueprint $table) {
            $table->dropColumn('activity');
        });
    }
};
