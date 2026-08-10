<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run was asked to print, so a preparation that failed can be run again.
 *
 * A batch whose preparation fails is cancelled with the reason on it and holds no jobs at
 * all - the badges went back to Pending, and nothing on the batch says which badges those
 * were. `requested_badge_ids` is written when the batch is opened, before any of the
 * expensive work, which is the only moment the selection is known and the only record that
 * survives the failure.
 *
 * `retry_of_batch_id` points a retry at the run it replaces. Batches are immutable, so a
 * retry is a new batch rather than a revived one, and without the link the failed run and
 * its second attempt read as two unrelated rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('print_batches', 'requested_badge_ids')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->json('requested_badge_ids')->nullable()->after('total_jobs');
            });
        }

        if (SchemaGuard::missingColumn('print_batches', 'retry_of_batch_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->foreignId('retry_of_batch_id')->nullable()->after('requested_badge_ids');
            });
        }

        if (! SchemaGuard::hasForeignKeyOn('print_batches', 'retry_of_batch_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->foreign('retry_of_batch_id')
                    ->references('id')
                    ->on('print_batches')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::hasForeignKeyOn('print_batches', 'retry_of_batch_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropForeign(['retry_of_batch_id']);
            });
        }

        if (SchemaGuard::hasColumn('print_batches', 'retry_of_batch_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropColumn('retry_of_batch_id');
            });
        }

        if (SchemaGuard::hasColumn('print_batches', 'requested_badge_ids')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropColumn('requested_badge_ids');
            });
        }
    }
};
