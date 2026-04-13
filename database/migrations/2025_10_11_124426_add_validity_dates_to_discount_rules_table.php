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
            $table->string('applicable_for')->default('sales_order')->after('business_id');
            // nullable() berarti promo bisa berlaku selamanya jika tanggal tidak diisi
            $table->timestamp('valid_from')->nullable()->after('is_active');
            $table->timestamp('valid_to')->nullable()->after('valid_from');
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
