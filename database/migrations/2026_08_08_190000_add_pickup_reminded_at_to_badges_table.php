<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When we last nudged the attendee that their badge is still at the desk.
 *
 * The reminder is sent by a scheduled command, so it needs somewhere to record that it has been sent.
 * Without this column a command that runs on a timer mails the same person on every run, and one
 * stuck scheduler turns a helpful nudge into a nightly nag to thousands of attendees.
 *
 * Nullable and never cleared: the reminder is once per badge, and a badge that was collected does not
 * need the stamp reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('badges', 'pickup_reminded_at')) {
                $table->timestamp('pickup_reminded_at')->nullable()->after('printed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('badges', 'pickup_reminded_at')) {
                $table->dropColumn('pickup_reminded_at');
            }
        });
    }
};
