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
        Schema::create('goods_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('return_number')->unique();

            // Lokasi spesifik (Bin/Rak) dari mana barang dikirim
            $table->foreignId('source_location_id')->constrained('locations');

            // Lokasi spesifik (Bin/Rak) ke mana barang ditujukan (misal: Lokasi Karantina)
            $table->foreignId('destination_location_id')->constrained('locations');

            // Status alur kerja retur
            $table->string('status')->default('draft'); // cth: draft, pending_approval, approved, shipped, received, completed, cancelled

            $table->text('notes')->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_returns');
    }
};
