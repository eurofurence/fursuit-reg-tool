<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per day the pickup reminder went out, per event.
 *
 * The day guard. `badges.pickup_reminded_at` already stops a second mail to the same person, but it
 * cannot stop a second *run*: an operator pressing "Send today's reminders" after the schedule has
 * already fired would mail everybody who became a candidate in between, which from the desk's side
 * looks like the button did nothing wrong and from the attendee's side is a second nudge nobody
 * asked for.
 *
 * The unique key on (event_id, ran_on) is the whole mechanism: both the scheduler and the button
 * claim the day by inserting, and exactly one of them wins. It is a database constraint rather than
 * a check-then-insert because two workers can run the same minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('badge_pickup_reminder_runs')) {
            Schema::create('badge_pickup_reminder_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();

                // The convention day, not a timestamp: "has today's send happened" is a question
                // about the calendar day the desk is working.
                $table->date('ran_on');

                // Who set it off, so the desk can tell the schedule from a colleague.
                $table->string('source');
                $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

                $table->unsignedInteger('attendees_notified')->default(0);
                $table->timestamp('ran_at');
                $table->timestamps();

                $table->unique(['event_id', 'ran_on'], 'badge_pickup_reminder_runs_day_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_pickup_reminder_runs');
    }
};
