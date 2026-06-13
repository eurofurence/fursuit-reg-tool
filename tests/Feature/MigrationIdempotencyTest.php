<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Locks in the behaviour of App\Support\Migrations\SchemaGuard, the helper that
 * makes our migrations safe to re-run after a partial failure (MySQL DDL is not
 * transactional). Runs on any driver.
 */
describe('SchemaGuard predicates', function () {
    beforeEach(function () {
        Schema::dropIfExists('guard_probe_child');
        Schema::dropIfExists('guard_probe');
        Schema::create('guard_probe', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->index('name'); // single-column index -> name "guard_probe_name_index"
        });
    });

    afterEach(function () {
        Schema::dropIfExists('guard_probe_child');
        Schema::dropIfExists('guard_probe');
    });

    test('missingColumn / hasColumn', function () {
        expect(SchemaGuard::hasColumn('guard_probe', 'name'))->toBeTrue();
        expect(SchemaGuard::missingColumn('guard_probe', 'name'))->toBeFalse();
        expect(SchemaGuard::missingColumn('guard_probe', 'nope'))->toBeTrue();
    });

    test('missingTable', function () {
        expect(SchemaGuard::missingTable('guard_probe'))->toBeFalse();
        expect(SchemaGuard::missingTable('does_not_exist'))->toBeTrue();
    });

    // Regression: a single column passed as a STRING must match an index defined
    // on that one column. Laravel's bare Schema::hasIndex only matches the index
    // *name* or the *full* column array, so 'name' would otherwise miss the
    // 'guard_probe_name_index' index and a re-run would throw "Duplicate key name".
    test('hasIndex matches a single column given as a string', function () {
        expect(SchemaGuard::hasIndex('guard_probe', 'name'))->toBeTrue();
        expect(SchemaGuard::hasIndex('guard_probe', ['name']))->toBeTrue();
        expect(SchemaGuard::hasIndex('guard_probe', 'guard_probe_name_index'))->toBeTrue();
        expect(SchemaGuard::hasIndex('guard_probe', 'missing_col'))->toBeFalse();
    });

    test('hasForeignKeyOn detects a foreign key by column', function () {
        Schema::create('guard_probe_child', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guard_probe_id')->constrained('guard_probe')->cascadeOnDelete();
        });

        expect(SchemaGuard::hasForeignKeyOn('guard_probe_child', 'guard_probe_id'))->toBeTrue();
        expect(SchemaGuard::hasForeignKeyOn('guard_probe_child', 'id'))->toBeFalse();
        expect(SchemaGuard::hasForeignKeyTo('guard_probe_child', 'guard_probe_id', 'guard_probe'))->toBeTrue();
        expect(SchemaGuard::hasForeignKeyTo('guard_probe_child', 'guard_probe_id', 'other'))->toBeFalse();
    });
});

/**
 * Proves the previously-failing migration is now safe to re-run after it has
 * already been applied (the exact production scenario). MySQL-only because it
 * relies on real DDL / foreign-key semantics; skipped on the sqlite test driver.
 */
describe('checkouts cashier migration idempotency', function () {
    test('re-running the migration on an already-migrated schema is a no-op', function () {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL DDL semantics.');
        }

        // The full migration set (incl. this migration) has already run via RefreshDatabase.
        $migration = require database_path('migrations/2025_08_25_030900_update_cashier_id_to_staff_in_checkouts_table.php');

        // Re-running up() must not throw (guards skip the already-applied steps).
        $migration->up();
        $migration->up();

        // End state still correct.
        expect(SchemaGuard::hasForeignKeyTo('checkouts', 'cashier_id', 'staff'))->toBeTrue();
        expect(SchemaGuard::hasForeignKeyTo('checkouts', 'legacy_cashier_id', 'users'))->toBeTrue();
        expect(SchemaGuard::hasColumn('checkouts', 'legacy_cashier_id'))->toBeTrue();
    });
});
