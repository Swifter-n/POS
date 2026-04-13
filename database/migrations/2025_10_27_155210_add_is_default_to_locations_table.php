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
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_default_staging')->default(false)->after('status');
            $table->boolean('is_default_receiving')->default(false)->after('is_default_staging'); // Atau MAIN untuk outlet

            // Tambahkan index untuk performa query
            $table->index(['locatable_type', 'locatable_id', 'zone_id', 'is_default_staging'], 'loc_default_stg_idx');
            $table->index(['locatable_type', 'locatable_id', 'zone_id', 'is_default_receiving'], 'loc_default_rcv_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
             $table->dropIndex('loc_default_stg_idx');
             $table->dropIndex('loc_default_rcv_idx');

            // Drop kolom
            $table->dropColumn('is_default_staging');
            $table->dropColumn('is_default_receiving');
        });
    }
};
