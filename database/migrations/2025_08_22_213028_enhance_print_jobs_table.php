<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            // Add new tracking fields
            if (SchemaGuard::missingColumn('print_jobs', 'queued_at')) {
                $table->timestamp('queued_at')->nullable()->after('printed_at');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('queued_at');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('started_at');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'error_message')) {
                $table->text('error_message')->nullable()->after('failed_at');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'retry_count')) {
                $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'priority')) {
                $table->unsignedTinyInteger('priority')->default(5)->after('retry_count');
            }
            if (SchemaGuard::missingColumn('print_jobs', 'processing_machine_id')) {
                $table->foreignId('processing_machine_id')->nullable()->constrained('machines')->nullOnDelete()->after('printer_id');
            }

            // Add indexes for performance
            if (! SchemaGuard::hasIndex('print_jobs', 'status')) {
                $table->index('status');
            }
            if (! SchemaGuard::hasIndex('print_jobs', ['status', 'priority', 'created_at'])) {
                $table->index(['status', 'priority', 'created_at']);
            }
            if (! SchemaGuard::hasIndex('print_jobs', 'processing_machine_id')) {
                $table->index('processing_machine_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::hasForeignKeyOn('print_jobs', 'processing_machine_id')) {
                $table->dropForeign(['processing_machine_id']);
            }
            if (SchemaGuard::hasIndex('print_jobs', ['status'])) {
                $table->dropIndex(['status']);
            }
            if (SchemaGuard::hasIndex('print_jobs', ['status', 'priority', 'created_at'])) {
                $table->dropIndex(['status', 'priority', 'created_at']);
            }
            if (SchemaGuard::hasIndex('print_jobs', ['processing_machine_id'])) {
                $table->dropIndex(['processing_machine_id']);
            }
            if (SchemaGuard::hasColumn('print_jobs', 'queued_at')) {
                $table->dropColumn('queued_at');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'started_at')) {
                $table->dropColumn('started_at');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'failed_at')) {
                $table->dropColumn('failed_at');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'error_message')) {
                $table->dropColumn('error_message');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'priority')) {
                $table->dropColumn('priority');
            }
            if (SchemaGuard::hasColumn('print_jobs', 'processing_machine_id')) {
                $table->dropColumn('processing_machine_id');
            }
        });
    }
};
