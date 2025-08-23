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
        Schema::create('printer_events', function (Blueprint $table) {
            $table->id();
            $table->string('printer_name');
            $table->string('event_type'); // PRINTER, JOB, etc.
            $table->string('status'); // OFFLINE, ONLINE, PAPER_OUT, etc.
            $table->string('severity')->default('INFO'); // INFO, WARN, ERROR, FATAL
            $table->text('message');
            $table->string('machine_name')->nullable();
            $table->json('raw_event')->nullable(); // Store the full QZ event
            $table->boolean('handled')->default(false); // Whether this event triggered an action
            $table->timestamp('event_time');
            $table->timestamps();
            
            $table->index(['printer_name', 'event_time']);
            $table->index(['status', 'severity']);
            $table->index('handled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_events');
    }
};
