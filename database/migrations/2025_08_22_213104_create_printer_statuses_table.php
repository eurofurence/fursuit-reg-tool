<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('status'); // PrinterStatusEnum
            $table->string('status_code')->nullable(); // e.g., 'media-empty', 'offline'
            $table->string('severity')->nullable(); // PrinterStatusSeverityEnum
            $table->text('message')->nullable();
            $table->json('metadata')->nullable(); // Additional status data
            $table->timestamps();
            
            $table->index(['printer_id', 'created_at']);
            $table->unique(['printer_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_statuses');
    }
};