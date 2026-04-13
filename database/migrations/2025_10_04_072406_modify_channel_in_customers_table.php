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
        Schema::table('customers', function (Blueprint $table) {
            // Hapus kolom string 'channel' yang lama
            $table->dropColumn('channel');
            // Tambahkan foreign key 'channel_id' yang baru
            $table->foreignId('channel_id')->nullable()->after('business_id')->constrained('channels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
            $table->dropColumn('channel_id');
            $table->string('channel')->nullable();
        });
    }
};
