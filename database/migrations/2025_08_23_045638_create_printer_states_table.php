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
        Schema::create('printer_states', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Printer name
            $table->enum('status', ['idle', 'working', 'paused'])->default('idle');
            $table->unsignedBigInteger('current_job_id')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('last_update')->useCurrent();
            $table->string('machine_name')->nullable(); // Which machine is handling this printer
            $table->timestamps();
            
            $table->index(['status', 'last_update']);
            $table->foreign('current_job_id')->references('id')->on('print_jobs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_states');
    }
};
