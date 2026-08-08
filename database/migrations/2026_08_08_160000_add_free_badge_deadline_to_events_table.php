<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cutoff for the badge that comes free with a registration.
 *
 * It needs its own column rather than borrowing one of the seven dates the event already
 * has. `mass_printed_at` is the closest in spirit and still wrong: it records when the
 * print run happened, which is not the date attendees are told to submit by, and
 * `event:state` moves the order window around in development without any regard for it.
 * The public FAQ and the Welcome page both quote this date, and quoting a date that means
 * something else is how "1 August" ended up rendering as "28 July".
 *
 * Nullable: an event that has no free badge simply has no deadline, and the pages drop
 * the clause rather than inventing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('events', 'free_badge_deadline')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dateTime('free_badge_deadline')->nullable()->after('order_ends_at');
            });
        }

        // Carry over what Welcome.vue used to hardcode, so the copy attendees have
        // already been shown does not change under them. Guarded on null so a value set
        // by hand in the admin is never overwritten by a re-run.
        DB::table('events')
            ->whereNull('free_badge_deadline')
            ->whereYear('starts_at', 2026)
            ->update(['free_badge_deadline' => '2026-08-01 00:00:00']);
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn('events', 'free_badge_deadline')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('free_badge_deadline');
            });
        }
    }
};
