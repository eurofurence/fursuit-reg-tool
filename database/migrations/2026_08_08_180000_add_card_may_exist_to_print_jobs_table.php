<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark the jobs where a card may physically exist but nothing recorded it.
 *
 * Happens when an agent dies mid-print: the artwork reached the spooler, a card
 * may be in the output bin, and the confirmation never arrived. The job is
 * failed so a human looks at it, but "failed" alone is indistinguishable from a
 * card that never printed -- and Resume requeues those, which printed a second
 * copy of a card somebody already had in their hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('print_jobs', 'card_may_exist')) {
                $table->boolean('card_may_exist')->default(false)->after('completion_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('print_jobs', 'card_may_exist')) {
                $table->dropColumn('card_may_exist');
            }
        });
    }
};
