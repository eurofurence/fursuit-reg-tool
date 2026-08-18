<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile colour the attendee picks.
 *
 * It holds a key from a fixed palette (see UserProfile::PALETTE), never a free
 * hex: a preset cannot carry content, which is why picking one is the single
 * profile change that does not send the row back through the review queue.
 * Null means "follow the fursuit", which is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('user_profiles', 'colour')) {
                $table->string('colour', 16)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('user_profiles', 'colour')) {
                $table->dropColumn('colour');
            }
        });
    }
};
