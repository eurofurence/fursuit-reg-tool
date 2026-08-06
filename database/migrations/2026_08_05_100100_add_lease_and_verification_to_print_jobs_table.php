<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two changes that together stop print jobs from being lost or duplicated.
     *
     * Leases: an agent claims a job for a bounded time and must keep renewing
     * it. If the agent or the Windows host dies, the lease expires and the job
     * returns to the queue instead of sitting claimed forever.
     *
     * Verification: a job may only reach Printed with evidence attached saying
     * how we know a card came out. The system this replaces inferred completion
     * from a ten second timer.
     */
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('print_jobs', 'print_batch_id')) {
                $table->foreignId('print_batch_id')->nullable()->after('printer_id')
                    ->constrained()->nullOnDelete();
            }

            // Position within the batch, assigned once when the batch is built.
            // Ordering badges by attendee is fiddly enough that doing it on every
            // claim would be both slow and a chance to disagree with itself.
            if (SchemaGuard::missingColumn('print_jobs', 'sequence')) {
                $table->unsignedInteger('sequence')->nullable()->after('print_batch_id');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'lease_expires_at')) {
                $table->timestamp('lease_expires_at')->nullable()->after('started_at');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'attempt_count')) {
                $table->unsignedTinyInteger('attempt_count')->default(0)->after('retry_count');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'completion_source')) {
                $table->string('completion_source')->nullable()->after('printed_at');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'verified_print_at')) {
                $table->timestamp('verified_print_at')->nullable()->after('completion_source');
            }

            // Only the outcome is stored. The camera frames and the OCR that
            // produced this decision stay on the agent machine; nothing is
            // uploaded.
            if (SchemaGuard::missingColumn('print_jobs', 'verification_source')) {
                $table->string('verification_source')->nullable()->after('verified_print_at');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'verified_by_id')) {
                $table->foreignId('verified_by_id')->nullable()->after('verification_source')
                    ->constrained('users')->nullOnDelete();
            }

            // Job id reported by the printer firmware over SNMP, and the UUID it
            // pairs with. These are what let the agent match a card the printer
            // says it finished to the job we asked it to print.
            if (SchemaGuard::missingColumn('print_jobs', 'firmware_job_id')) {
                $table->string('firmware_job_id')->nullable()->after('verified_by_id');
            }

            if (SchemaGuard::missingColumn('print_jobs', 'firmware_job_uuid')) {
                $table->string('firmware_job_uuid')->nullable()->after('firmware_job_id');
            }
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('print_jobs', ['print_batch_id', 'status'])) {
                $table->index(['print_batch_id', 'status']);
            }

            if (! SchemaGuard::hasIndex('print_jobs', ['status', 'lease_expires_at'])) {
                $table->index(['status', 'lease_expires_at']);
            }
        });

        // The `file` column was created NOT NULL, but PrintBadgeJob::failed()
        // already writes rows without one. Re-applying a ->change() is safe.
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('file')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::hasIndex('print_jobs', ['print_batch_id', 'status'])) {
                $table->dropIndex(['print_batch_id', 'status']);
            }

            if (SchemaGuard::hasIndex('print_jobs', ['status', 'lease_expires_at'])) {
                $table->dropIndex(['status', 'lease_expires_at']);
            }
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            foreach (['print_batch_id', 'verified_by_id'] as $column) {
                if (SchemaGuard::hasForeignKeyOn('print_jobs', $column)) {
                    $table->dropForeign([$column]);
                }
            }
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $columns = [
                'print_batch_id', 'sequence', 'lease_expires_at', 'attempt_count', 'completion_source',
                'verified_print_at', 'verification_source', 'verified_by_id',
                'firmware_job_id', 'firmware_job_uuid',
            ];

            foreach ($columns as $column) {
                if (SchemaGuard::hasColumn('print_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
