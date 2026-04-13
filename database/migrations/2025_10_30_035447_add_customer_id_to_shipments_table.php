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
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('source_plant_id')
                  ->nullable()
                  ->after('business_id') // Sesuaikan 'after' jika perlu
                  ->constrained('plants')
                  ->nullOnDelete();

            $table->foreignId('destination_plant_id')
                  ->nullable()
                  ->after('source_plant_id')
                  ->constrained('plants')
                  ->nullOnDelete();

            // Ini kolom yang Anda sebutkan
            $table->foreignId('destination_outlet_id')
                  ->nullable()
                  ->after('destination_plant_id')
                  ->constrained('outlets')
                  ->nullOnDelete();

            // Ini kolom asli dari file ini
            $table->foreignId('customer_id')
                  ->nullable()
                  ->after('destination_outlet_id') // ->after() ini sekarang valid
                  ->constrained('customers') // Asumsi customer ada di tabel 'contacts'
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            try {
                 $table->dropForeign(['customer_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('customer_id');

             try {
                 $table->dropForeign(['destination_outlet_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('destination_outlet_id');

             try {
                 $table->dropForeign(['destination_plant_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('destination_plant_id');

             try {
                 $table->dropForeign(['source_plant_id']);
             } catch (\Exception $e) { /* Abaikan */ }
             $table->dropColumn('source_plant_id');
        });
    }
};
