# Migrations must be idempotent

Every migration in this repo must be safe to re-run. Read this before writing one. The short version
is in `CLAUDE.md`; this is the full rule and the reason for it.

## Why

Migrations run as an ArgoCD PreSync **Job** (`php artisan migrate --force`) against MySQL, and
**MySQL DDL is not transactional** - a migration that fails partway leaves its applied steps in place
but is never recorded, so the next run hits "Duplicate column / key / table" and blocks every later
migration (this caused a dev outage; see `docs/bugfix-02-*.md` - those write-ups have never been
committed to this repository, so the rule below is the whole surviving record).

## The guards

Every migration must therefore be safe to re-run. Guard each operation with
`App\Support\Migrations\SchemaGuard`:

- `Schema::create('t', …)` → wrap in `if (SchemaGuard::missingTable('t')) { … }` (and use
  `Schema::dropIfExists` in `down()`).
- add/drop column → `SchemaGuard::missingColumn(...)` / `SchemaGuard::hasColumn(...)`.
- add/drop index or unique → `SchemaGuard::hasIndex($table, $nameOrColumns)`.
- add/drop foreign key → `SchemaGuard::hasForeignKeyOn(...)` / `hasForeignKeyTo(...)`.
- `->change()` may be left unguarded (re-applying is safe). Data `UPDATE`s should use `WHERE` guards
  so they converge. Order destructive steps so a new FK is only added after conflicting data is
  cleared.

`tests/Feature/MigrationIdempotencyTest.php` locks in the helper's behaviour.
