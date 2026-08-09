<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event opening hours for the on-site badge desk.
 *
 * Sits beside `pickup_booths` for the same reason it does: the desk is staffed for that
 * event's schedule, so the hours belong to the event row rather than to config, and an
 * operator retiming the desk between two convention days does an edit, not a deploy.
 *
 * Null means "no hours published", which is what every existing row gets and what the
 * public pickup page renders as silence. There is no built-in default here, unlike the
 * booth split: a wrong booth range sends someone to the next counter, a wrong opening
 * time sends them to a closed hall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('events', 'desk_opening_hours')) {
                $table->json('desk_opening_hours')->nullable()->after('pickup_booths');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'desk_opening_hours')) {
                $table->dropColumn('desk_opening_hours');
            }
        });
    }
};
