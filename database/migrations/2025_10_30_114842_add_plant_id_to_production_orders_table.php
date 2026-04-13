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
            $table->foreignId('plant_id')
                  ->after('business_id') // Asumsi business_id sudah ada
                  ->constrained('plants')
                  ->cascadeOnDelete();

            // Tambahkan/pastikan kolom lain ada
            if (!Schema::hasColumn('production_orders', 'production_order_number')) {
                 $table->string('production_order_number')->unique()->after('id');
            }
            if (!Schema::hasColumn('production_orders', 'status')) {
                 $table->string('status')->default('draft')->after('quantity_planned');
            }
            if (!Schema::hasColumn('production_orders', 'created_by_user_id')) {
                 $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
             if (!Schema::hasColumn('production_orders', 'started_at')) {
                 $table->timestamp('started_at')->nullable();
             }
             if (!Schema::hasColumn('production_orders', 'completed_at')) {
                 $table->timestamp('completed_at')->nullable();
             }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            try {
                 $table->dropForeign(['plant_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('plant_id');

        });
    }
};
