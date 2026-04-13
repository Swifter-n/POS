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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('material_code');
            $table->string('sku');
            $table->string('name');
            $table->string('base_uom'); // Contoh: 'gram', 'ml', 'PCS'
            $table->integer('min_sled_days')->nullable()->default(0);
            $table->text('description')->nullable();
            $table->string('product_type')->default('finished_good');
            $table->foreignId('category_id')
                  ->constrained('categories')
                  ->onDelete('cascade');
            $table->foreignId('brand_id')
                  ->nullable()
                  ->constrained('brands')
                  ->onDelete('cascade');
            $table->foreignId('business_id')
                  ->constrained('businesses')
                  ->onDelete('cascade');
            $table->string('thumbnail')->nullable();
            $table->string('color')->nullable();
            $table->decimal('price', 8, 2);
            $table->decimal('cost', 8, 2);
            $table->integer('rating')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('status')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
