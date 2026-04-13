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
             $table->foreignId('fleet_id')->nullable()->after('business_id')->constrained('fleets');
	         $table->date('scheduled_for')->nullable()->after('status')->comment('Tanggal pengiriman dijadwalkan');
             $table->string('estimated_time_of_arrival')->nullable()->after('scheduled_for');
	         $table->decimal('transport_cost', 15, 2)->nullable()->after('estimated_time_of_arrival');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            //
        });
    }
};
