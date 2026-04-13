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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');

            // Informasi Utama
            $table->string('name');
            $table->string('channel')->comment('Horeca, Modern Trade, General Trade');
            $table->foreignId('area_id')->nullable()->constrained('areas');
            $table->boolean('status')->default(true);

            // Informasi Kontak
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();

            // Informasi Alamat Terstruktur
            $table->text('address')->nullable()->comment('Nama jalan, nomor rumah, RT/RW');


            // Informasi Kredit
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
