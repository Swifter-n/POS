<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Members (Data Pelanggan Setia)
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            // Multi-tenant: Member terikat pada satu bisnis
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->unique(); // Kunci identifikasi utama di POS

            // Loyalty Info
            $table->decimal('current_points', 10, 2)->default(0);
            $table->string('tier')->default('Silver'); // Silver, Gold, Platinum

            // QR Token (String unik untuk generate barcode member di aplikasi customer)
            $table->string('qr_token')->unique()->nullable();

            // Password/PIN (Untuk login di aplikasi customer nanti)
            $table->string('password')->nullable();

            $table->timestamps();
        });

        // 2. Tabel Member Vouchers (Dompet Voucher)
        // Menghubungkan Member dengan 'DiscountRule' yang sudah ada
        Schema::create('member_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // Relasi ke tabel 'discount_rules' (Mesin Diskon Utama)
            $table->foreignId('discount_rule_id')->constrained('discount_rules')->cascadeOnDelete();

            // Kode Unik (cth: BDAY-BUDI-001) untuk di-scan/input di POS
            $table->string('code')->unique();

            // Status Penggunaan
            $table->boolean('is_used')->default(false);
            $table->dateTime('used_at')->nullable();

            // Masa Berlaku (Bisa override dari rule utama jika perlu)
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();

            $table->timestamps();
        });

        // 3. Update Tabel Orders (Agar transaksi tercatat milik member siapa)
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('customer_name')->constrained('members')->nullOnDelete();
            // Tambahan: Poin yang didapat/dipakai dari transaksi ini
            $table->decimal('points_earned', 10, 2)->default(0)->after('total_items');
            $table->decimal('points_redeemed', 10, 2)->default(0)->after('points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn(['member_id', 'points_earned', 'points_redeemed']);
        });
        Schema::dropIfExists('member_vouchers');
        Schema::dropIfExists('members');
    }
};
