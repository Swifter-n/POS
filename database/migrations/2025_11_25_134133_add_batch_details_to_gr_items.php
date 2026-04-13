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
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->string('batch')->nullable()->after('uom');
            $table->date('sled')->nullable()->comment('Shelf Life End Date / Expiry')->after('batch');

            // Tambahan: Tanggal Produksi (diperlukan untuk rumus generate batch Anda)
            $table->date('manufacturing_date')->nullable()->after('sled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['batch', 'sled', 'manufacturing_date']);
        });
    }
};
