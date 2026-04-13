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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            // Relasi ke Outlet (Wajib untuk Multi-cabang)
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->string('code'); // Nama Meja (cth: T-01)
            $table->string('name')->nullable();

            // Posisi untuk Layout di Tablet (Default 0)
            $table->double('x_position')->default(0);
            $table->double('y_position')->default(0);
            $table->integer('capacity')->default(0);

            // Data QR Code (bisa berupa link atau string unik)
            $table->string('qr_content')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
