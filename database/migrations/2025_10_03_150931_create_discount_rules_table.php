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
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->string('name')->comment('Nama aturan, misal: Diskon Horeca Kopi');
            $table->integer('priority')->default(0)->comment('Urutan aplikasi diskon, 0 dieksekusi lebih dulu');
            $table->boolean('is_active')->default(true);

            // -- KONDISI (JIKA...) --
            $table->string('customer_channel')->nullable()->comment('Horeca, Modern Trade, dll.');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->unsignedInteger('min_quantity')->nullable();

            // -- AKSI (MAKA...) --
            $table->enum('discount_type', ['percentage', 'fixed_amount']);
            $table->decimal('discount_value', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_rules');
    }
};
