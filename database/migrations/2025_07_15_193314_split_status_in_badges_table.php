<?php

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
     * Idempotent: the add/rename/non-null steps are guarded so a partial prior
     * run can be completed on re-run. The data UPDATEs are convergent.
     */
    public function up(): void
    {
        // Add status_payment (must happen before the rename, since ->after('status'))
        if (SchemaGuard::missingColumn('badges', 'status_payment')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->string('status_payment', 255)->after('status')->nullable(); // Temporarily nullable to avoid issues with existing data
            });
        }

        // Rename status -> status_fulfillment
        if (SchemaGuard::hasColumn('badges', 'status') && SchemaGuard::missingColumn('badges', 'status_fulfillment')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->renameColumn('status', 'status_fulfillment');
            });
        }

        if (SchemaGuard::hasColumn('badges', 'status_payment') && SchemaGuard::hasColumn('badges', 'status_fulfillment')) {
            // Payment status migration
            DB::statement('UPDATE badges SET status_payment = "paid" WHERE status_fulfillment IN ("ready_for_pickup", "picked_up") OR total = 0');
            DB::statement('UPDATE badges SET status_payment = "unpaid" WHERE status_payment IS NULL');

            // Fulfillment status migration
            DB::statement('UPDATE badges SET status_fulfillment = "printed" WHERE status_fulfillment IN ("printed", "unpaid")');
            // No-op updates for completeness
            DB::statement('UPDATE badges SET status_fulfillment = "ready_for_pickup" WHERE status_fulfillment = "ready_for_pickup"');
            DB::statement('UPDATE badges SET status_fulfillment = "picked_up" WHERE status_fulfillment = "picked_up"');
            DB::statement('UPDATE badges SET status_fulfillment = "pending" WHERE status_fulfillment = "pending"');

            Schema::table('badges', function (Blueprint $table) {
                $table->string('status_payment', 255)->after('status_fulfillment')->nullable(false)->change(); // Make it non-nullable after migration
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore kinda the original values of status
        // DOES NOT RESTORE THE OLD DATA 100%
        if (SchemaGuard::hasColumn('badges', 'status_fulfillment') && SchemaGuard::hasColumn('badges', 'status_payment')) {
            DB::statement('UPDATE badges SET status_fulfillment = "printed" WHERE status_fulfillment = "printed"');
            DB::statement('UPDATE badges SET status_fulfillment = "ready_for_pickup" WHERE status_fulfillment = "ready_for_pickup"');
            DB::statement('UPDATE badges SET status_fulfillment = "picked_up" WHERE status_fulfillment = "picked_up"');
            DB::statement('UPDATE badges SET status_fulfillment = "pending" WHERE status_fulfillment = "pending"');
            DB::statement('UPDATE badges SET status_fulfillment = "unpaid" WHERE status_payment = "unpaid"');
        }

        // Restore the original structure
        if (SchemaGuard::hasColumn('badges', 'status_fulfillment') && SchemaGuard::missingColumn('badges', 'status')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->renameColumn('status_fulfillment', 'status');
            });
        }
        if (SchemaGuard::hasColumn('badges', 'status_payment')) {
            Schema::table('badges', function (Blueprint $table) {
                $table->dropColumn('status_payment');
            });
        }
    }
};
