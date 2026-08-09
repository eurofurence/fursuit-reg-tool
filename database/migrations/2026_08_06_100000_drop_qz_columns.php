<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop what QZ Tray left behind.
     *
     * Printing no longer runs through the browser. The Zebra card printer is
     * driven by the native print agent, which reads the hardware over SNMP
     * rather than trusting a driver that reports "online" regardless of whether
     * the printer is jammed. See docs/printing.md.
     *
     * The job-level QZ fields are replaced by firmware_job_id / firmware_job_uuid,
     * which carry the printer's own job identifiers, and the machine-level ones
     * by agent_last_seen_at / agent_version.
     */
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            foreach (['qz_job_name', 'last_qz_status', 'last_qz_message'] as $column) {
                if (SchemaGuard::hasColumn('print_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('machines', function (Blueprint $table) {
            foreach (['qz_connection_status', 'qz_last_seen_at'] as $column) {
                if (SchemaGuard::hasColumn('machines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('print_jobs', 'qz_job_name')) {
                $table->string('qz_job_name')->nullable();
            }
            if (SchemaGuard::missingColumn('print_jobs', 'last_qz_status')) {
                $table->string('last_qz_status')->nullable();
            }
            if (SchemaGuard::missingColumn('print_jobs', 'last_qz_message')) {
                $table->text('last_qz_message')->nullable();
            }
        });

        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'qz_connection_status')) {
                $table->string('qz_connection_status')->default('disconnected');
            }
            if (SchemaGuard::missingColumn('machines', 'qz_last_seen_at')) {
                $table->timestamp('qz_last_seen_at')->nullable();
            }
        });
    }
};
