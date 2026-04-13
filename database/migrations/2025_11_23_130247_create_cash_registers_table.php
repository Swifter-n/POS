<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Header Shift (Sesi Kasir)
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Hubungkan ke Outlet (User locationable)
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('open'); // open, closed

            // Uang Fisik
            $table->decimal('opening_amount', 15, 2)->default(0);
            $table->decimal('closing_amount', 15, 2)->nullable(); // Diinput kasir saat tutup

            // Rekap Sistem (Snapshot saat tutup)
            $table->decimal('total_cash_sales', 15, 2)->default(0);      // Uang Tunai dari Penjualan
            $table->decimal('total_card_sales', 15, 2)->default(0);      // Non-Tunai
            $table->decimal('total_points_redeemed', 15, 2)->default(0); // Poin yang dibakar (Loyalty)

            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->text('closing_note')->nullable();

            $table->timestamps();
        });

        // 2. Transaksi Laci (Arus Uang)
        Schema::create('cash_register_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);

            // Tipe: 'sell' (Jual), 'refund', 'add_cash' (Modal Tambahan), 'payout' (Ambil Uang), 'opening'
            $table->string('transaction_type');

            $table->string('pay_method')->default('cash'); // cash, card, etc
            $table->enum('type', ['credit', 'debit']); // credit=masuk laci, debit=keluar laci

            // Link ke Order jika berasal dari penjualan
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 3. Tambah relasi ke Tabel Orders
        Schema::table('orders', function (Blueprint $table) {
            // Menyimpan ID Shift saat transaksi DIBAYAR
            $table->foreignId('cash_register_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['cash_register_id']);
            $table->dropColumn('cash_register_id');
        });
        Schema::dropIfExists('cash_register_transactions');
        Schema::dropIfExists('cash_registers');
    }
};
