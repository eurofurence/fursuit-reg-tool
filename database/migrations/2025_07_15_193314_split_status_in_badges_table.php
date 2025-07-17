<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->renameColumn('status', 'status_fulfillment');
            $table->string('status_payment', 255)->after('status_fulfillment')->nullable(); // Temporarily nullable to avoid issues with existing data
        });

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore kinda the original values of status
        // DOES NOT RESTORE THE OLD DATA 100%
        DB::statement('UPDATE badges SET status_fulfillment = "printed" WHERE status_fulfillment = "printed"');
        DB::statement('UPDATE badges SET status_fulfillment = "ready_for_pickup" WHERE status_fulfillment = "ready_for_pickup"');
        DB::statement('UPDATE badges SET status_fulfillment = "picked_up" WHERE status_fulfillment = "picked_up"');
        DB::statement('UPDATE badges SET status_fulfillment = "pending" WHERE status_fulfillment = "pending"');
        DB::statement('UPDATE badges SET status_fulfillment = "unpaid" WHERE status_payment = "unpaid"');

        Schema::table('badges', function (Blueprint $table) {
            // Restore the original structure
            $table->renameColumn('status_fulfillment', 'status');
            $table->dropColumn('status_payment');
        });
    }
};
