<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which desk clerk sent a batch to the printer, and what they have
 * already been told about it.
 *
 * `created_by_id` cannot carry this: it points at `users`, the admin accounts,
 * and the POS authenticates a clerk through the `machine-user` guard against
 * the separate `staff` table. Every batch queued from the POS therefore had no
 * owner at all, which is why a clerk had no way to see their own runs.
 *
 * `desk_dismissed_status` stores the batch status the clerk acknowledged rather
 * than a plain timestamp: a run dismissed while paused has to speak up again
 * once it finishes, and one boolean cannot say that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('print_batches', 'created_by_staff_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->foreignId('created_by_staff_id')->nullable()->after('created_by_id');
            });
        }

        if (! SchemaGuard::hasForeignKeyOn('print_batches', 'created_by_staff_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->foreign('created_by_staff_id')
                    ->references('id')
                    ->on('staff')
                    ->nullOnDelete();
            });
        }

        if (SchemaGuard::missingColumn('print_batches', 'desk_dismissed_status')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->string('desk_dismissed_status')->nullable()->after('created_by_staff_id');
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::hasForeignKeyOn('print_batches', 'created_by_staff_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropForeign(['created_by_staff_id']);
            });
        }

        if (SchemaGuard::hasColumn('print_batches', 'created_by_staff_id')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropColumn('created_by_staff_id');
            });
        }

        if (SchemaGuard::hasColumn('print_batches', 'desk_dismissed_status')) {
            Schema::table('print_batches', function (Blueprint $table) {
                $table->dropColumn('desk_dismissed_status');
            });
        }
    }
};
