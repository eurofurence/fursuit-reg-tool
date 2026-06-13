<?php

use App\Support\Migrations\SchemaGuard;
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
            if (SchemaGuard::missingColumn('checkouts', 'tse_start_timestamp')) {
                $table->timestamp('tse_start_timestamp')->nullable()->comment('TSE Vorgangsbeginn timestamp for KassenSichV §6 compliance');
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_end_timestamp')) {
                $table->timestamp('tse_end_timestamp')->nullable()->comment('TSE Vorgangsende timestamp for KassenSichV §6 compliance');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('checkouts', 'tse_start_timestamp')) {
                $table->dropColumn('tse_start_timestamp');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_end_timestamp')) {
                $table->dropColumn('tse_end_timestamp');
            }
        });
    }
};
