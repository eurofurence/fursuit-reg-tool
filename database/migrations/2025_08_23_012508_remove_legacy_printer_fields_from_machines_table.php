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
        Schema::table('machines', function (Blueprint $table) {
            $table->dropForeign(['badge_printer_id']);
            $table->dropForeign(['receipt_printer_id']);
            $table->dropColumn(['badge_printer_id', 'receipt_printer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('badge_printer_id')->nullable()->constrained('printers');
            $table->foreignId('receipt_printer_id')->nullable()->constrained('printers');
        });
    }
};
