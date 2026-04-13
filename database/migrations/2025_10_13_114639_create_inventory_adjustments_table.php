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
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('location_id')->constrained();

            // Kolom ini akan terisi jika adjustment dibuat otomatis dari Stock Count
            $table->foreignId('stock_count_id')->nullable()->constrained()->onDelete('set null');

            $table->string('status')->default('posted'); // posted, cancelled
            $table->string('type')->comment('cth: STOCK_COUNT, MANUAL_WRITE_OFF');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
