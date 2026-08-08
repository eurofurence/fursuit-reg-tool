<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event pickup booth ranges.
 *
 * The desk splits its queue across booths by attendee id, and the useful cut points
 * depend on how that event's ids are distributed, so the split belongs to the event
 * rather than to config. Null means "use the built-in default", which is what every
 * existing row gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('events', 'pickup_booths')) {
                $table->json('pickup_booths')->nullable()->after('mass_printed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'pickup_booths')) {
                $table->dropColumn('pickup_booths');
            }
        });
    }
};
