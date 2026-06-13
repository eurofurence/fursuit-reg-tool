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
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::hasForeignKeyOn('machines', 'badge_printer_id')) {
                $table->dropForeign(['badge_printer_id']);
            }
            if (SchemaGuard::hasForeignKeyOn('machines', 'receipt_printer_id')) {
                $table->dropForeign(['receipt_printer_id']);
            }
            if (SchemaGuard::hasColumn('machines', 'badge_printer_id')) {
                $table->dropColumn('badge_printer_id');
            }
            if (SchemaGuard::hasColumn('machines', 'receipt_printer_id')) {
                $table->dropColumn('receipt_printer_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'badge_printer_id')) {
                $table->foreignId('badge_printer_id')->nullable()->constrained('printers');
            }
            if (SchemaGuard::missingColumn('machines', 'receipt_printer_id')) {
                $table->foreignId('receipt_printer_id')->nullable()->constrained('printers');
            }
        });
    }
};
