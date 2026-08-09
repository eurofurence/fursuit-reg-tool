<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff are archived, never deleted.
 *
 * A `staff` row is the only thing tying a badge handout, a checkout and a print
 * run to the person who did it (`badges.picked_up_by_staff_id`,
 * `checkouts.cashier_id`, `print_batches.created_by_staff_id`, all
 * `nullOnDelete`). Deleting a member therefore did not just remove a login, it
 * silently detached every statistic they had ever generated. There is no reason
 * to ever do that: a volunteer who stops staffing needs their login to stop
 * working, not their history to disappear.
 *
 * `archived_at` replaces the `is_active` boolean rather than joining it. Two
 * flags meaning "may this person log in" is one flag too many, and the timestamp
 * answers "since when" for free. Inactive members become archived members at the
 * moment this runs, so nobody who was locked out gets let back in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('staff', 'archived_at')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->index();
            });
        }

        // Carry the old flag over before it goes. Guarded on both sides so a
        // re-run cannot re-stamp a member who was archived and then restored.
        if (SchemaGuard::hasColumn('staff', 'is_active')) {
            DB::table('staff')
                ->where('is_active', false)
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);

            Schema::table('staff', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::missingColumn('staff', 'is_active')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('pin_code');
            });
        }

        if (SchemaGuard::hasColumn('staff', 'archived_at')) {
            DB::table('staff')
                ->whereNotNull('archived_at')
                ->update(['is_active' => false]);

            Schema::table('staff', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
