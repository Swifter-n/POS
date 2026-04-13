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
        Schema::create('stock_count_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained(); // User yang melakukan perhitungan
            $table->string('team_name')->nullable(); // cth: 'Team Kuning'
            $table->integer('counted_quantity')->comment('Hasil hitungan dari user/tim ini');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_entries');
    }
};
