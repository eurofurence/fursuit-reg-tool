<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\Schema;

/**
 * Idempotency predicates for migrations.
 *
 * MySQL DDL is not transactional: if a migration performs several DDL/DML
 * steps and fails partway, the applied steps persist while the migration is
 * never recorded in the `migrations` table. Re-running it then throws
 * "Duplicate column", "Duplicate key name", "table already exists", etc.,
 * which blocks every subsequent migration.
 *
 * Guarding each schema operation with these predicates makes a migration safe
 * to re-run: it converges to the intended end state from any partial state.
 * The helpers are driver-agnostic (MySQL in prod/dev, sqlite in tests) and are
 * safe to call from anonymous migration classes.
 */
class SchemaGuard
{
    public static function missingTable(string $table): bool
    {
        return ! Schema::hasTable($table);
    }

    public static function missingColumn(string $table, string $column): bool
    {
        return ! Schema::hasColumn($table, $column);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    /**
     * Determine whether $table has an index identified by a name or by the
     * exact set of columns it covers.
     *
     * @param  string|array<int, string>  $index  Index name or column list.
     */
    public static function hasIndex(string $table, string|array $index, ?string $type = null): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        // Laravel's hasIndex matches a string only against an index *name* or the
        // *exact* column-array. When callers pass a single column name as a string
        // (e.g. 'from_id'), also treat it as a one-column index lookup so it matches
        // an index defined on that single column.
        if (Schema::hasIndex($table, $index, $type)) {
            return true;
        }

        return is_string($index) && Schema::hasIndex($table, [$index], $type);
    }

    /**
     * Return the first foreign key on $table that covers $column, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function foreignKeyOn(string $table, string $column): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return $foreignKey;
            }
        }

        return null;
    }

    public static function hasForeignKeyOn(string $table, string $column): bool
    {
        return self::foreignKeyOn($table, $column) !== null;
    }

    /**
     * Whether $column has a foreign key that references $foreignTable.
     */
    public static function hasForeignKeyTo(string $table, string $column, string $foreignTable): bool
    {
        $foreignKey = self::foreignKeyOn($table, $column);

        return $foreignKey !== null && ($foreignKey['foreign_table'] ?? null) === $foreignTable;
    }
}
