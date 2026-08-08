<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A print batch is the unit of work an operator selects in the print agent.
     * A printer finishes one batch before starting another, which is what lets
     * the queue stop dead on a jam instead of draining past it.
     */
    public function up(): void
    {
        if (SchemaGuard::missingTable('print_batches')) {
            Schema::create('print_batches', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('status')->default('draft');

                // Denormalised progress counters, kept current by PrintBatch so the
                // agent and the admin panel can poll progress without aggregating
                // over every job on every request.
                $table->unsignedInteger('total_jobs')->default(0);
                $table->unsignedInteger('printed_count')->default(0);
                $table->unsignedInteger('verified_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

                $table->text('pause_reason')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'printer_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('print_batches');
    }
};
