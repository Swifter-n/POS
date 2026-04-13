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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')
                  ->constrained('businesses')
                  ->onDelete('cascade');
            $table->foreignId('outlet_id')->nullable()
                  ->constrained('outlets')
                  ->onDelete('cascade');
            // $table->foreignId('role_id')
            //       ->constrained('roles')
            //       ->onDelete('cascade');
            $table->boolean('status')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
