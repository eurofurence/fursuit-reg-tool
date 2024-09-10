<?php

use App\Domain\Printing\Models\Printer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignIdFor(Printer::class,'receipt_printer_id')->after('id')->nullable()->constrained('printers')->cascadeOnDelete();
            $table->foreignIdFor(Printer::class,'badge_printer_id')->after('id')->nullable()->constrained('printers')->cascadeOnDelete();
        });
    }

};
