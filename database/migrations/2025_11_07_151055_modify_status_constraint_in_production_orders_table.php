<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $newStatusList = [
    'draft',
    'approved', // <-- TAMBAHKAN INI
    'insufficient_materials',
    'pending_picking',
    'ready_to_produce',
    'in_progress',
    'completed',
];

    // Daftar status LAMA (tanpa 'draft') untuk rollback
    private $oldStatusList = [
        'insufficient_materials',
        'pending_picking',
        'ready_to_produce',
        'in_progress',
        'completed',
    ];
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE production_orders DROP CONSTRAINT production_orders_status_check');

        // 2. Buat ulang constraint dengan daftar status yang baru
        // Mengubah array PHP menjadi string format SQL ('value1', 'value2')
        $statusString = "'" . implode("', '", $this->newStatusList) . "'";

        DB::statement("
            ALTER TABLE production_orders ADD CONSTRAINT production_orders_status_check
            CHECK (status IN ({$statusString}))
        ");

        // 3. Ubah default kolom untuk memastikan
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE production_orders DROP CONSTRAINT production_orders_status_check');

        // 2. Kembalikan constraint ke versi lama (tanpa 'draft')
        $statusString = "'" . implode("', '", $this->oldStatusList) . "'";

        DB::statement("
            ALTER TABLE production_orders ADD CONSTRAINT production_orders_status_check
            CHECK (status IN ({$statusString}))
        ");

        // 3. Kembalikan default (asumsi default sebelumnya adalah salah satu dari list lama)
         Schema::table('production_orders', function (Blueprint $table) {
            $table->string('status')->default('pending_picking')->change(); // Sesuaikan jika default-nya beda
        });
    }
};
