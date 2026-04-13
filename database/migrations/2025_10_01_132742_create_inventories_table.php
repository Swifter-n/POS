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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            // $table->morphs('stockable'); // Membuat `stockable_id` & `stockable_type` (untuk Warehouse/Outlet)
            // $table->foreignId('location_id')->nullable()->constrained('locations');
            // $table->foreignId('product_id')->constrained('products');
            // $table->string('batch')->nullable();
            // $table->date('sled')->nullable()->comment('Shelf Life Expiration Date');
            // $table->string('type')->default('available')->comment('available, quality_inspection, blocked');
            // $table->integer('avail_stock')->default(0);
            $table->foreignId('business_id')->constrained()->onDelete('cascade');

            // Menunjuk ke lokasi paling spesifik tempat barang disimpan (biasanya BIN atau PALLET)
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');

            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('batch')->nullable();
            $table->date('sled')->nullable()->comment('Shelf Life End Date');
            $table->integer('avail_stock')->default(0);
            $table->softDeletes();
            $table->timestamps();
            // Index unik agar satu produk di satu lokasi hanya punya satu record per batch
            $table->unique(['location_id', 'product_id', 'batch']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
