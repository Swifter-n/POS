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
        Schema::table('barcodes', function (Blueprint $table) {
            $table->morphs('barcodeable', 'idx_barcodeable'); // 'idx_barcodeable' adalah nama index

            // 2. Hapus "lem lama" yang hanya untuk Meja/Outlet
            // Kita drop constraint dulu, baru drop kolom
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');

            // 3. Ganti nama 'table_number' menjadi 'code' yang lebih umum
            $table->renameColumn('table_number', 'code');

            // 4. Ganti 'qr_value' menjadi 'value' yang lebih umum
            $table->renameColumn('qr_value', 'value');

            // 5. Tambahkan kolom 'type' untuk membedakan (QR vs Barcode biasa)
            $table->string('type')->default('qr_code')->after('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('barcodes', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->constrained('outlets');

            // 2. Hapus kolom polimorfik
            $table->dropMorphs('barcodeable', 'idx_barcodeable');

            // 3. Kembalikan nama kolom lama
            $table->renameColumn('code', 'table_number');
            $table->renameColumn('value', 'qr_value');

            // 4. Hapus kolom 'type'
            $table->dropColumn('type');
        });
    }
};
