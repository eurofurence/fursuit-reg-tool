<?php

use App\Domain\Printing\Models\Printer;
use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'receipt_printer_id')) {
                $table->foreignIdFor(Printer::class, 'receipt_printer_id')->after('id')->nullable()->constrained('printers')->cascadeOnDelete();
            }
            if (SchemaGuard::missingColumn('machines', 'badge_printer_id')) {
                $table->foreignIdFor(Printer::class, 'badge_printer_id')->after('id')->nullable()->constrained('printers')->cascadeOnDelete();
            }
        });
    }
};
