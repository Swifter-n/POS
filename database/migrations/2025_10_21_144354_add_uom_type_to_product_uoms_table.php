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
        Schema::table('product_uoms', function (Blueprint $table) {
            $table->string('uom_type')->after('uom_name')->default('selling'); // cth: purchasing, selling, production
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_uoms', function (Blueprint $table) {
            //
        });
    }
};
