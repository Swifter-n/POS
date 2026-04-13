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
        Schema::create('area_sales_team_pivot', function (Blueprint $table) {
            // Kolom untuk ID dari tabel 'areas'
            $table->foreignId('area_id')->constrained()->onDelete('cascade');

            // Kolom untuk ID dari tabel 'sales_teams'
            $table->foreignId('sales_team_id')->constrained()->onDelete('cascade');

            // Menjadikan kedua kolom sebagai primary key gabungan
            // Ini untuk memastikan satu area tidak bisa di-assign ke tim yang sama lebih dari sekali.
            $table->primary(['area_id', 'sales_team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_sales_team_pivot');
    }
};
