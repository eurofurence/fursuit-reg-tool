<?php

use App\Models\Event;
use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent: every column/index/foreign-key/data step is guarded so a
     * partial prior run can be safely completed on re-run.
     */
    public function up(): void
    {
        $update = Event::where('id', 2)->count() > 0; // EF 29 - only migrate data if the event exists

        // ---------------------------------------------------------------- user_achievements
        if (SchemaGuard::missingColumn('user_achievements', 'event_user_id')) {
            Schema::table('user_achievements', function (Blueprint $table) {
                $table->unsignedBigInteger('event_user_id')->nullable()->after('user_id');
            });
        }
        if (! SchemaGuard::hasForeignKeyOn('user_achievements', 'event_user_id')) {
            Schema::table('user_achievements', function (Blueprint $table) {
                $table->foreign('event_user_id')->references('id')->on('event_users')->onUpdate('cascade')->onDelete('restrict');
            });
        }

        // Migrate data while the old user_id column still exists
        if ($update && SchemaGuard::hasColumn('user_achievements', 'user_id')) {
            DB::statement('
                UPDATE user_achievements ua
                JOIN event_users eu ON ua.user_id = eu.user_id AND eu.event_id = ?
                SET ua.event_user_id = eu.id
            ', [2]);
        }

        // Drop the old user_id foreign key / constraints / column (FK first)
        if (SchemaGuard::hasForeignKeyOn('user_achievements', 'user_id')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->dropForeign(['user_id']));
        }
        if (SchemaGuard::hasIndex('user_achievements', 'user_achievements_user_id_achievement_unique')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->dropUnique('user_achievements_user_id_achievement_unique'));
        }
        if (SchemaGuard::hasIndex('user_achievements', 'user_achievements_user_id_earned_at_index')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->dropIndex('user_achievements_user_id_earned_at_index'));
        }
        if (SchemaGuard::hasColumn('user_achievements', 'user_id')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->dropColumn('user_id'));
        }

        // Enforce NOT NULL and add the new index + unique constraint
        Schema::table('user_achievements', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable(false)->change();
        });
        if (! SchemaGuard::hasIndex('user_achievements', 'user_achievements_event_user_id_index')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->index('event_user_id'));
        }
        if (! SchemaGuard::hasIndex('user_achievements', 'user_achievements_achievement_event_user_id_unique')) {
            Schema::table('user_achievements', fn (Blueprint $t) => $t->unique(['achievement', 'event_user_id']));
        }

        // ---------------------------------------------------------------- user_catches
        if (SchemaGuard::missingColumn('user_catches', 'event_user_id')) {
            Schema::table('user_catches', function (Blueprint $table) {
                $table->unsignedBigInteger('event_user_id')->nullable()->after('id');
            });
        }
        if (! SchemaGuard::hasForeignKeyOn('user_catches', 'event_user_id')) {
            Schema::table('user_catches', function (Blueprint $table) {
                $table->foreign('event_user_id')->references('id')->on('event_users')->onUpdate('cascade')->onDelete('restrict');
            });
        }

        if ($update && SchemaGuard::hasColumn('user_catches', 'user_id') && SchemaGuard::hasColumn('user_catches', 'event_id')) {
            DB::statement('
                UPDATE user_catches uc
                JOIN event_users eu
                    ON uc.user_id = eu.user_id AND uc.event_id = eu.event_id
                SET uc.event_user_id = eu.id
            ');
        }

        if (SchemaGuard::hasForeignKeyOn('user_catches', 'user_id')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropForeign(['user_id']));
        }
        if (SchemaGuard::hasForeignKeyOn('user_catches', 'event_id')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropForeign(['event_id']));
        }
        if (SchemaGuard::hasIndex('user_catches', 'user_catches_user_id_fursuit_id_unique')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropUnique('user_catches_user_id_fursuit_id_unique'));
        }
        if (SchemaGuard::hasIndex('user_catches', 'idx_user_catches_user_fursuit')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropIndex('idx_user_catches_user_fursuit'));
        }
        if (SchemaGuard::hasIndex('user_catches', 'idx_user_catches_user_created')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropIndex('idx_user_catches_user_created'));
        }
        if (SchemaGuard::hasColumn('user_catches', 'user_id')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropColumn('user_id'));
        }
        if (SchemaGuard::hasColumn('user_catches', 'event_id')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->dropColumn('event_id'));
        }

        Schema::table('user_catches', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable(false)->change();
        });
        if (! SchemaGuard::hasIndex('user_catches', 'user_catches_event_user_id_index')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->index('event_user_id'));
        }
        if (! SchemaGuard::hasIndex('user_catches', 'user_catches_fursuit_id_event_user_id_unique')) {
            Schema::table('user_catches', fn (Blueprint $t) => $t->unique(['fursuit_id', 'event_user_id']));
        }
    }

    /**
     * Reverse the migrations.
     *
     * No automated down path: this is a destructive user_id -> event_user_id
     * conversion and the original migration intentionally left it a no-op.
     */
    public function down(): void
    {
        // intentionally left as a no-op
    }
};
