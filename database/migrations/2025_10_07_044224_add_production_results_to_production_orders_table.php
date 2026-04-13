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
        Schema::table('production_orders', function (Blueprint $table) {
        $table->renameColumn('quantity_to_produce', 'quantity_planned');
        $table->unsignedInteger('quantity_produced')->nullable()->after('quantity_planned');
        $table->unsignedInteger('quantity_failed')->nullable()->after('quantity_produced');
        $table->text('notes_for_failed')->nullable()->after('quantity_failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            //
        });
    }
};
