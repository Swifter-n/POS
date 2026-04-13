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
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->unique();
            $table->foreignId('business_id')
                  ->constrained('businesses')
                  ->onDelete('cascade');
            $table->string('ownership_type')->default('internal')->comment('internal, mitra');
            $table->text('address')->nullable()->comment('Nama jalan, nomor rumah, RT/RW');
            $table->string('phone')->nullable();
            $table->text('description')->nullable();
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
        Schema::dropIfExists('outlets');
    }
};
