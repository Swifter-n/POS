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
        Schema::table('inventory_moves', function (Blueprint $table) {
            $table->decimal('input_quantity', 15, 4)->nullable()->after('quantity_base');
            $table->string('input_uom')->nullable()->after('input_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_moves', function (Blueprint $table) {
            $table->dropColumn(['input_quantity', 'input_uom']);
        });
    }
};
