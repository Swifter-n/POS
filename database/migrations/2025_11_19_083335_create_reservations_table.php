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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            // Meja opsional saat booking (bisa assign nanti), tapi idealnya dipilih di awal
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->integer('guest_count')->default(1);

            $table->dateTime('reservation_time'); // Waktu booking

            // Status: booked, seated, cancelled, completed
            $table->string('status')->default('booked');

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
