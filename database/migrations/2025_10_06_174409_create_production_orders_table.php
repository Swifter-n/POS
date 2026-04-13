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
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('production_order_number')->unique();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('finished_good_id')->constrained('products');
            $table->unsignedInteger('quantity_to_produce');
            $table->enum('status', ['pending', 'materials_available', 'insufficient_materials', 'ready_to_pick', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
