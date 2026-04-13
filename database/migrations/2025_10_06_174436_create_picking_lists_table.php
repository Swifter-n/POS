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
        Schema::create('picking_lists', function (Blueprint $table) {
            $table->id();
            $table->string('picking_list_number')->unique();
            $table->morphs('sourceable');
            $table->foreignId('user_id')->comment('Staf yang ditugaskan')->constrained('users');
            $table->timestamp('started_at')->nullable(); // Waktu picker mulai mengambil
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('picking_lists');
    }
};
