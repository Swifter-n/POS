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
        // Schema::create('stock_transfers', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('transfer_number')->unique();
        //     $table->string('delivery_order_number')->nullable();
        //     $table->foreignId('business_id')->constrained('businesses');

        //     // Lokasi Asal
        //     $table->foreignId('from_warehouse_id')->constrained('warehouses');

        //     // Lokasi Tujuan (Polimorfik, bisa ke Outlet atau Gudang lain)
        //     $table->morphs('to_stockable');

        //     $table->enum('status', [
        //         'pending', 'out_of_stock', 'partially_available', 'available_for_picking',
        //         'ready_to_pick', 'picking_in_progress', 'shipped', 'received', 'cancelled'
        //     ])->default('pending');

        //     // Tracking User & Tanggal
        //     $table->foreignId('requested_by_user_id')->constrained('users');
        //     $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
        //     $table->foreignId('shipped_by_user_id')->nullable()->constrained('users');
        //     $table->foreignId('received_by_user_id')->nullable()->constrained('users');
        //     $table->foreignId('assigned_user_id')->nullable()->after('approved_by_user_id')->constrained('users');
        //     $table->timestamp('started_at')->nullable();
        //     $table->timestamp('completed_at')->nullable();

        //     $table->timestamp('request_date')->nullable();
        //     $table->timestamp('ship_date')->nullable();
        //     $table->timestamp('receive_date')->nullable(); // Tanggal POD

        //     $table->text('notes')->nullable();
        //     $table->softDeletes();
        //     $table->timestamps();
        //});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //Schema::dropIfExists('stock_transfers');
    }
};
