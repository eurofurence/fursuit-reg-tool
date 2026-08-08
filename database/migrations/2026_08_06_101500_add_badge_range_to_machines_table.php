<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendee-ID range this desk is responsible for.
     *
     * On the first day the badges are split into crates by attendee ID and one
     * crate goes to one desk, so a desk's "left to pick up" counter is only
     * useful when it counts its own crate. Both ends are nullable: unset means
     * the desk counts every badge, which is the state every other day.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'badge_range_min')) {
                $table->unsignedInteger('badge_range_min')->nullable();
            }

            if (SchemaGuard::missingColumn('machines', 'badge_range_max')) {
                $table->unsignedInteger('badge_range_max')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            foreach (['badge_range_min', 'badge_range_max'] as $column) {
                if (SchemaGuard::hasColumn('machines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
