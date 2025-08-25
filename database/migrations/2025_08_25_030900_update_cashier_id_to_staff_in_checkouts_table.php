<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            // Add legacy_cashier_id column to preserve old user references
            $table->unsignedBigInteger('legacy_cashier_id')->nullable()->after('cashier_id');
            $table->foreign('legacy_cashier_id')->references('id')->on('users')->nullOnDelete();
        });

        // Copy existing cashier_id values to legacy_cashier_id
        DB::statement('UPDATE checkouts SET legacy_cashier_id = cashier_id WHERE cashier_id IS NOT NULL');

        Schema::table('checkouts', function (Blueprint $table) {
            // Drop the old foreign key constraint
            $table->dropForeign(['cashier_id']);
            
            // Make cashier_id nullable
            $table->unsignedBigInteger('cashier_id')->nullable()->change();
            
            // Add the new foreign key constraint to the staff table
            $table->foreign('cashier_id')->references('id')->on('staff')->nullOnDelete();
        });

        // Clear cashier_id since they were pointing to users, not staff
        DB::statement('UPDATE checkouts SET cashier_id = NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore cashier_id values from legacy_cashier_id
        DB::statement('UPDATE checkouts SET cashier_id = legacy_cashier_id WHERE legacy_cashier_id IS NOT NULL');

        Schema::table('checkouts', function (Blueprint $table) {
            // Drop the staff foreign key constraint
            $table->dropForeign(['cashier_id']);
            
            // Make cashier_id not nullable again
            $table->unsignedBigInteger('cashier_id')->nullable(false)->change();
            
            // Restore the old foreign key constraint to the users table
            $table->foreign('cashier_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('checkouts', function (Blueprint $table) {
            // Drop the legacy_cashier_id column
            $table->dropForeign(['legacy_cashier_id']);
            $table->dropColumn('legacy_cashier_id');
        });
    }
};