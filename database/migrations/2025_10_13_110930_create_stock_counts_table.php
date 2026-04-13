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
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_number')->unique();
            $table->foreignId('business_id')->constrained();

            // Lokasi (bisa berupa Warehouse atau Outlet) tempat perhitungan dilakukan
            $table->morphs('countable');

            $table->string('status')->default('draft'); // draft, in_progress, pending_validation, completed, posted, cancelled
            $table->json('assigned_teams')->nullable(); // cth: {"counters": [1, 2], "validators": [3]}
            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
