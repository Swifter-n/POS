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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('email_customer')->nullable()->after('customer_phone');
            $table->text('address_customer')->nullable()->after('customer_phone');
            $table->string('city_customer')->nullable()->after('customer_phone');
            $table->string('post_code_customer')->nullable()->after('customer_phone');
            $table->string('proof')->nullable()->after('status');;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};
