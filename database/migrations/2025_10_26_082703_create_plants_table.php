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
        Schema::create('plants', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Kode Plant (e.g., 1111)
            $table->string('name');           // Nama Plant (e.g., Gunung Putri)
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->text('address')->nullable();
            $table->foreignId('village_id')->nullable()->constrained('villages')->after('address');
            $table->string('type')->comment('MANUFACTURING, DISTRIBUTION, OTHER');
            // Tambahkan kolom lain jika perlu (e.g., province_id, regency_id, etc.)
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plants');
    }
};
