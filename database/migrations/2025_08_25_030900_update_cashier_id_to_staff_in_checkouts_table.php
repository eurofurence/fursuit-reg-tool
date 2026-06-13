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
     * Idempotent: every schema step is guarded so the migration converges to the
     * intended end state (cashier_id -> staff, old values preserved in
     * legacy_cashier_id) from any partial state. MySQL DDL is not transactional,
     * so a previous partial run can leave some of these steps already applied.
     */
    public function up(): void
    {
        // 1. Preserve old user references in legacy_cashier_id (add column if missing)
        if (SchemaGuard::missingColumn('checkouts', 'legacy_cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_cashier_id')->nullable()->after('cashier_id');
            });
        }

        if (! SchemaGuard::hasForeignKeyOn('checkouts', 'legacy_cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->foreign('legacy_cashier_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        // 2. Copy existing cashier_id values to legacy_cashier_id (only fill empty slots)
        DB::statement('UPDATE checkouts SET legacy_cashier_id = cashier_id WHERE cashier_id IS NOT NULL AND legacy_cashier_id IS NULL');

        // 3. Drop the old cashier_id -> users foreign key if it is still present
        if (SchemaGuard::hasForeignKeyTo('checkouts', 'cashier_id', 'users')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->dropForeign(['cashier_id']);
            });
        }

        // 4. Make cashier_id nullable (safe to repeat)
        Schema::table('checkouts', function (Blueprint $table) {
            $table->unsignedBigInteger('cashier_id')->nullable()->change();
        });

        // 5. Clear cashier_id values (they pointed to users, now preserved in legacy_cashier_id)
        //    BEFORE adding the staff foreign key, so leftover user ids cannot violate it.
        DB::statement('UPDATE checkouts SET cashier_id = NULL WHERE cashier_id IS NOT NULL');

        // 6. Add the new cashier_id -> staff foreign key if missing
        if (! SchemaGuard::hasForeignKeyOn('checkouts', 'cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->foreign('cashier_id')->references('id')->on('staff')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore cashier_id values from legacy_cashier_id
        DB::statement('UPDATE checkouts SET cashier_id = legacy_cashier_id WHERE legacy_cashier_id IS NOT NULL');

        if (SchemaGuard::hasForeignKeyTo('checkouts', 'cashier_id', 'staff')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->dropForeign(['cashier_id']);
            });
        }

        Schema::table('checkouts', function (Blueprint $table) {
            $table->unsignedBigInteger('cashier_id')->nullable(false)->change();
        });

        if (! SchemaGuard::hasForeignKeyOn('checkouts', 'cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->foreign('cashier_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (SchemaGuard::hasForeignKeyOn('checkouts', 'legacy_cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->dropForeign(['legacy_cashier_id']);
            });
        }

        if (SchemaGuard::hasColumn('checkouts', 'legacy_cashier_id')) {
            Schema::table('checkouts', function (Blueprint $table) {
                $table->dropColumn('legacy_cashier_id');
            });
        }
    }
};
