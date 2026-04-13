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
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Tipe Rule:
            // - 'transaction_multiplier' (Belanja hari senin poin 2x)
            // - 'product_bonus' (Beli Kopi +10 poin)
            // - 'category_bonus' (Beli Makanan +5 poin)
            $table->string('type');

            $table->decimal('value', 10, 2); // Nilai Multiplier (cth: 2.0) atau Fixed Poin (cth: 10)

            // Kondisi JSON (misal: {'product_id': 1} atau {'days': ['Mon', 'Tue']})
            $table->json('conditions')->nullable();

            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_rules');
    }
};
