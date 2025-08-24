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
        Schema::table('checkouts', function (Blueprint $table) {
            $table->timestamp('tse_start_timestamp')->nullable()->comment('TSE Vorgangsbeginn timestamp for KassenSichV §6 compliance');
            $table->timestamp('tse_end_timestamp')->nullable()->comment('TSE Vorgangsende timestamp for KassenSichV §6 compliance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn(['tse_start_timestamp', 'tse_end_timestamp']);
        });
    }
};
