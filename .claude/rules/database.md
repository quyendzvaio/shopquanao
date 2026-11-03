# Database & migrations

- MariaDB 10.11. Base schema: `database/schema.sql`; incremental migrations in `database/migrations/` (`.sql` only; the runner ignores other files).
- All SQL lives in `src/repository/` (permanent exceptions: `MigrationRunner`, `LangfuseTraceOutbox`, `LangfuseOtlpTracePublisher`); `ArchitectureTest` fails on `->prepare/query/exec` anywhere else. A new query is a method on a repository class.
- Runner: `MigrationRunner` (`src/core/database/MigrationRunner.php`), invoked only from `scripts/run_database_migrations.php` — never on web requests; locked by MariaDB advisory lock; applied migrations tracked in `_migrations` table.
- Production/CI gate: migrations must PASS before the app starts (compose `--wait db redis`, then `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`).
- New schema changes: add a dated migration file (`YYYY_MM_DD_name.sql`); never edit applied migrations.
- Integration tests need DB `shop_test` (`scripts/dev/test-db.sh` creates it in the Compose MariaDB; `scripts/dev/test-integration.sh` runs them); CI imports schema then applies migrations before running them.
- Knowledge corpus re-index after document changes: `docker compose exec -T app php scripts/ingest_knowledge.php`.
