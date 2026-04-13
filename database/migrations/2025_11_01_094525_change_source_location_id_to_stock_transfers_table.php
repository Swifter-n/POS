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
        Schema::table('stock_transfers', function (Blueprint $table) {
            try {
                $table->dropForeign(['source_location_id']);
            } catch (\Exception $e) { /* Abaikan jika tidak ada */ }

            $table->unsignedBigInteger('source_location_id')->nullable()->change();

            // Tambahkan kembali foreign key (dengan nullOnDelete)
            $table->foreign('source_location_id')
                  ->references('id')
                  ->on('locations')
                  ->nullOnDelete();


            // Pastikan destination_location_id juga nullable
            // (Meskipun di schema Anda sudah 'YES', ini untuk memastikan)
             try {
                $table->dropForeign(['destination_location_id']);
            } catch (\Exception $e) { /* Abaikan */ }

            $table->unsignedBigInteger('destination_location_id')->nullable()->change();

             $table->foreign('destination_location_id')
                  ->references('id')
                  ->on('locations')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            //
        });
    }
};
