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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');

            // Informasi Utama
            $table->string('name');
            $table->string('type')->comment('Contoh: Transporter, Supplier, Service, dll.');
            $table->string('contact_person')->nullable();

            // Informasi Kontak
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Informasi Alamat
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();

            // Informasi Bank & Pajak
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('tax_id')->nullable()->comment('Contoh: NPWP');

            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
