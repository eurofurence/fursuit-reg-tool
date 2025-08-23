<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            // Add new tracking fields
            $table->timestamp('queued_at')->nullable()->after('printed_at');
            $table->timestamp('started_at')->nullable()->after('queued_at');
            $table->timestamp('failed_at')->nullable()->after('started_at');
            $table->text('error_message')->nullable()->after('failed_at');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            $table->unsignedTinyInteger('priority')->default(5)->after('retry_count');
            $table->foreignId('processing_machine_id')->nullable()->constrained('machines')->nullOnDelete()->after('printer_id');

            // Add indexes for performance
            $table->index('status');
            $table->index(['status', 'priority', 'created_at']);
            $table->index('processing_machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['processing_machine_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'priority', 'created_at']);
            $table->dropIndex(['processing_machine_id']);
            $table->dropColumn([
                'queued_at',
                'started_at',
                'failed_at',
                'error_message',
                'retry_count',
                'priority',
                'processing_machine_id',
            ]);
        });
    }
};
