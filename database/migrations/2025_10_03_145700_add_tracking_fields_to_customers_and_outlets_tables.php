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
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('total_order_count')->default(0)->after('status');
            $table->decimal('total_spend', 15, 2)->default(0)->after('total_order_count');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedBigInteger('total_order_count')->default(0)->after('status');
            $table->decimal('total_spend', 15, 2)->default(0)->after('total_order_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['total_order_count', 'total_spend']);
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['total_order_count', 'total_spend']);
        });
    }
};
