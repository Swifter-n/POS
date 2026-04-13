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
        Schema::create('game_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // Nama Label di Roda (cth: "Diskon 50%")
            $table->string('type')->default('voucher'); // 'voucher', 'point', 'zonk'

            // Jika tipe voucher, hubungkan ke rule
            $table->foreignId('discount_rule_id')->nullable()->constrained('discount_rules')->nullOnDelete();

            // Jika tipe point, berapa poin didapat
            $table->integer('point_reward')->default(0);

            // PROBABILITAS (0 - 100%)
            // Semakin besar angkanya, semakin sering keluar
            $table->integer('probability')->default(10);

            $table->string('color_code')->default('#FF0000'); // Warna untuk UI Roda
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_rewards');
    }
};
