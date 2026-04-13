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
        Schema::table('shipments', function (Blueprint $table) {
             if (Schema::hasColumn('shipments', 'sourceable_type') && Schema::hasColumn('shipments', 'sourceable_id')) {
                // Hapus foreign key/index jika ada (nama default: shipments_sourceable_type_sourceable_id_index)
                // $table->dropIndex(['sourceable_type', 'sourceable_id']);
                $table->dropColumn(['sourceable_type', 'sourceable_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {

        });
    }
};
