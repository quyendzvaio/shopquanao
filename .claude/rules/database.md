# Database & migrations

- MariaDB 10.11. Base schema: `database/schema.sql`; incremental migrations in `database/migrations/` (`.sql` only; the runner ignores other files).
- All SQL lives in `src/repository/` (the one permanent exception: `MigrationRunner`); `ArchitectureTest` fails on `->prepare/query/exec` anywhere else. A new query is a method on a repository class.
- Runner: `MigrationRunner` (`src/core/database/MigrationRunner.php`), invoked only from `scripts/run_database_migrations.php` — never on web requests; locked by MariaDB advisory lock; applied migrations tracked in `_migrations` table.
- Production/CI gate: migrations must PASS before the app starts (compose `--wait db redis`, then `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`).
- New schema changes: add a dated migration file (`YYYY_MM_DD_name.sql`); never edit applied migrations.
- Integration tests need DB `shop_test` (`scripts/dev/test-db.sh` creates it in the Compose MariaDB; `scripts/dev/test-integration.sh` runs them); CI imports schema then applies migrations before running them.
- `support_tickets` (migration `2026_11_03_support_tickets.sql`): order-support tickets filed through the chatbot or handled by admins. Columns: `user_id` (FK `users`), nullable `order_id`, `type` (`doi_tra`, `hang_loi`, `huy_don`, `doi_dia_chi`, `khac`), `summary` (<= 500 chars), `status` (`moi`, `dang_xu_ly`, `da_xong`, `tu_choi`), `admin_note`, `chat_session_id`, `created_at`, `updated_at`; indexes on `(user_id, status)` and `(status, created_at)`. Only `SupportTicketRepository` runs its SQL; only PHP inserts rows, after the customer confirms in chat.
- Knowledge corpus re-index after document changes: `docker compose exec -T app php scripts/ingest_knowledge.php`.
