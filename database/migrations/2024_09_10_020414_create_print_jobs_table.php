<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Domain\Printing\Models\Printer::class)->constrained()->cascadeOnDelete();
            $table->morphs('printable');
            $table->string('type');
            $table->string('status');
            $table->string('file');
            $table->dateTime('printed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
