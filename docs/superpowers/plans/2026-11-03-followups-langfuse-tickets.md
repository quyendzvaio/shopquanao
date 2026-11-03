# Follow-ups: production-only Langfuse, ticket quick replies, ticket pagination — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three follow-ups after PR #25:
1. Remove the unused PHP Langfuse outbox, and let `chat-graph` trace only in production.
2. Add "Đồng ý" / "Huỷ" quick-reply buttons for a pending ticket draft in the chat widget.
3. Paginate the support-ticket lists on the admin page and the profile page.

**Architecture:**
- **Tracing gate:** a new `APP_ENV` env var decides production. Only `chat-graph/src/config.ts` reads it for tracing, and the deploy job writes `APP_ENV=production` into the server `.env`.
- **Quick replies:** the widget reads the existing `ticket_pending` flag and sends the fixed phrases PHP already understands.
- **Pagination:** done in `SupportTicketRepository` with `LIMIT`/`OFFSET` plus `COUNT`. `SupportTicketService` returns page metadata to the two page handlers.

**Tech Stack:** PHP 8.2 (classmap, no namespaces), MariaDB 10.11, TypeScript `chat-graph` (Node 22, `node --test`), inline JS in `templates/partials/chatbox.php`, Docker Compose, GitHub Actions.

**Spec:** the design approved in chat on 2026-11-03 (no separate spec file). Its points are copied verbatim into Global Constraints below; the support-ticket spec is `docs/superpowers/specs/2026-11-03-support-ticket-design.md`.

## Global Constraints

- **Branch:** `feat/followups-langfuse-tickets` from `develop` ca6a1f4. One PR goes to `develop`, and only after the user's go-ahead. Never commit to `develop` or `master`.
- **`APP_ENV`:** values `development` (default when unset or empty) or `production`. `chat-graph` sends traces only when all three hold:
  - `APP_ENV=production`;
  - `LANGFUSE_ENABLED=true`;
  - both Langfuse keys are set.

  In any other environment it never traces. When `LANGFUSE_ENABLED=true` but `APP_ENV` is not `production`, it logs this line once at startup: `[chat-graph] Langfuse tracing off: APP_ENV is not production`. The Compose `observability` profile stays.
- **Outbox removal:** delete the following. Never edit the applied migration `2026_09_01_langfuse_trace_outbox.sql`; add a new one instead.
  - the table, via the new migration `database/migrations/2026_11_03_drop_langfuse_trace_outbox.sql`, which contains `DROP TABLE IF EXISTS langfuse_trace_outbox;`;
  - `scripts/publish_langfuse_trace_outbox.php`;
  - `src/core/observability/LangfuseTraceOutbox.php` and `LangfuseOtlpTracePublisher.php`, plus their tests if any;
  - the compose service `langfuse-trace-publisher`;
  - every `LANGFUSE_PUBLISH_*` variable in compose, `.env.example` and the `ci.yml` deploy block;
  - the `Dockerfile` copy of the script;
  - the two `ArchitectureTest` SQL exceptions.
- **Quick replies:**
  - The buttons appear only under the bot message whose response has `ticket_pending === true`. They are labelled `Đồng ý` and `Huỷ`, and send exactly the messages `đồng ý` and `thôi`.
  - The buttons are removed when any new message is sent, whether by a button or typed.
  - Text is set with `textContent`, never `innerHTML` with data.
  - The stream path (`chat.complete` event) and the JSON fallback (`POST /api/chatbot`) both work.
- **Page sizes:**
  - admin: 20 per page, `?page=N`, keeping `?status=`;
  - profile: 10 per page, `?ticket_page=N`, keeping the other query params such as the order status filter.

  A page below 1, or one that is not a number, becomes 1; a page past the end becomes the last page; zero rows give page 1 of 1. Newest first (`created_at DESC, id DESC`). The bot tool `get_support_tickets` still returns 5.
- **Pager copy:** links `Trước` / `Sau`, shown only when that page exists, and the text `Trang {page}/{pages}`.
- **Layer rules:** SQL only in `src/repository/`, and `ArchitectureTest` must pass without new exceptions. After the outbox goes, the only SQL exception is `MigrationRunner`. Admin pages stay behind `AdminGuard`. Pagination links are GET, which is fine because they are read-only.
- **Commits:** end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.env`, `reports/`, `docs/*.docx` or `output/`. `.claude/rules/*.md` need `git add -f`. Never `docker compose down`, never `--remove-orphans`, never touch volumes.

## Review Focus

- A production deploy whose server `.env` lacks `APP_ENV` traces nothing, which is fine. A dev `.env` with `APP_ENV=production` copied by mistake would trace, which is acceptable because it is explicit. Unit-test both directions.
- After the drop migration runs on a database that never had the outbox table, nothing fails (`IF EXISTS`). The integration test DB in CI applies all migrations in order.
- The admin "Tất cả" filter with more than 20 tickets of mixed statuses is one ordered query, not a per-status merge, so page 2 is correct across statuses.
- A profile URL with both an order filter and `ticket_page` keeps both when paging.
- The quick-reply buttons must not stay clickable after the draft is answered or replaced. A double click sends only one message.

---

### Task 1: Remove the Langfuse outbox; chat-graph traces only in production

**Files:**
- Create: `database/migrations/2026_11_03_drop_langfuse_trace_outbox.sql`
- Delete: `scripts/publish_langfuse_trace_outbox.php`, `src/core/observability/LangfuseTraceOutbox.php`, `src/core/observability/LangfuseOtlpTracePublisher.php` (plus the directory if empty and any tests of them).
- Modify:
  - `docker-compose.yml`: drop the `langfuse-trace-publisher` service and the `LANGFUSE_PUBLISH_*` lines; add `APP_ENV: ${APP_ENV:-development}` to `chat-graph`'s environment.
  - `.env.example`: drop `LANGFUSE_PUBLISH_*`; add `APP_ENV=development` with a one-line comment.
  - `Dockerfile:34`: drop the script.
  - `.github/workflows/ci.yml`:
    - deploy block around lines 403–415: drop the three `LANGFUSE_PUBLISH_*` lines and add `echo "APP_ENV=production" >> .env`;
    - any place that starts `langfuse-trace-publisher`.
  - `tests/unit/ArchitectureTest.php:50-51`.
  - `chat-graph/src/config.ts` and `chat-graph/src/tracing.ts` (or wherever the enabled flag is computed).
- Docs: `README.md:157`, `docs/langfuse-observability.md`, `.claude/rules/observability.md`, `.claude/rules/database.md`, `.claude/rules/architecture.md` (the "only allowlist" sentence), `src/repository/README.md`, and the CLAUDE.md layer rule ("permanent exceptions: `MigrationRunner` and the two Langfuse outbox files").
- Test: `chat-graph/tests/` (config or tracing test).

**Interfaces:**
- Produces: `GraphConfig['langfuse'].enabled` is true only under the `APP_ENV` rule. The new field `GraphConfig['appEnv']` is `'production' | 'development'`.

- [ ] **Step 1:** Write failing node tests. With `LANGFUSE_ENABLED=true` and both keys set:
  - `APP_ENV` unset → `enabled === false`;
  - `APP_ENV=development` → `false`;
  - `APP_ENV=production` → `true`;
  - `APP_ENV=production` with `LANGFUSE_ENABLED=false` → `false`.

  Also test that the startup log line is emitted exactly once when tracing is enabled but the environment is not production. Inject the logger or capture `console`, following the existing test style.
- [ ] **Step 2:** Run `npm --prefix chat-graph test`. The new tests fail.
- [ ] **Step 3:** Implement the gate and the log line.
- [ ] **Step 4:** Delete the outbox files and references, and add the drop migration. Then:
  - Run `git grep -n -e LangfuseTraceOutbox -e LangfuseOtlpTracePublisher -e langfuse-trace-publisher -e publish_langfuse -e LANGFUSE_PUBLISH -e langfuse_trace_outbox`. The only hits allowed are the old migration, the new drop migration and historical `docs/superpowers/{plans,specs}` or `docs/plans` files.
  - Run `scripts/dev/composer dump-autoload`.
- [ ] **Step 5:** Run the following:
  - `docker compose config --quiet`;
  - `npm --prefix chat-graph test && npm --prefix chat-graph run build`;
  - `SKIP_SNAPSHOT=1 scripts/dev/gate.sh` → `GATE PASSED` (`turnDeadlineAnswersBusy` is known to be timing-flaky; if it alone fails, rerun once);
  - the migration gate on the dev DB: `docker compose run --rm --no-deps app php scripts/run_database_migrations.php` → the drop migration is applied.
- [ ] **Step 6:** Commit `chore(observability)!: remove the unused Langfuse outbox; chat-graph traces only when APP_ENV=production`.

### Task 2: Chat widget quick replies for a pending ticket

**Files:**
- Modify: `templates/partials/chatbox.php`:
  - the stream `chat.complete` handling around line 488;
  - the JSON fallback handling;
  - message rendering.
- Modify: the widget stylesheet under `public/assets/css/`, whichever file styles the chat box.

**Interfaces:**
- Consumes:
  - the stream `complete` event's `ticket_pending`, which PHP adds to `chat.complete`: check `src/app/api/support/ChatStreamProtocol` and the stream controller for the exact field path;
  - the JSON response's `ticket_pending`.

- [ ] **Step 1:** Find where the widget learns a turn's final metadata on both paths, and where it sends a user message. Reuse the existing send function so that buttons and typing go through the same path, including CSRF and session handling.
- [ ] **Step 2:** Implement the following:
  - When `ticket_pending === true`, append a `.chat-quick-replies` row with the two buttons under that bot message.
  - A click removes every quick-reply row first, which makes a double click send once, then sends the phrase.
  - Sending any message removes all rows.
- [ ] **Step 3:** Verify end to end:
  1. Rebuild `app` (`docker compose build app && docker compose up -d --no-deps app`).
  2. Run `scripts/dev/smoke.sh` → 0 FAIL.
  3. Do a browser check if a browser tool is available. Log in as a test user who has an order, ask for a size exchange on that order, see the two buttons, and click `Đồng ý`. The ticket is created ("Đã gửi yêu cầu #…") and the buttons are gone.
  4. If no browser is available, prove the page serves the new code with `curl`, prove the JSON path returns `ticket_pending` with a scripted `curl` session, and report that the click was not tested in a browser.
- [ ] **Step 4:** Commit `feat(widget): Đồng ý / Huỷ quick replies under a pending ticket draft`.

### Task 3: Paginate support tickets (admin 20, profile 10)

**Files:**
- Modify: `src/repository/SupportTicketRepository.php`, `src/service/support/SupportTicketService.php`, `src/app/web/admin/AdminTicketsPage.php`, `templates/pages/admin/tickets.php`, `src/app/web/ProfilePage.php`, `templates/pages/profile.php`.
- Test: `tests/unit/service/support/SupportTicketServiceTest.php` (SQLite fixture `tests/support/SqliteShop.php`), the page tests that already exist for `AdminTicketsPage` and `ProfilePage`, and `tests/integration` if `SupportTicketRepository` has integration tests there.

**Interfaces:**
- Repository:
  - `listForAdmin(array $statuses, int $limit, int $offset): array`, which replaces the unpaged version, with the same columns and order;
  - `countForAdmin(array $statuses): int`;
  - `listForUserPage(int $userId, int $limit, int $offset): array`;
  - `countForUser(int $userId): int`.

  `listForUser(int $userId, int $limit)` stays for the tool.
- Service:
  - `adminList(?string $status, int $page): array{tickets: list<array>, page: int, pages: int, total: int}`, where `null` means open (`moi`, `dang_xu_ly`), `'all'` means every status in one query, and an unknown status gives an empty page 1 of 1;
  - `profileList(int $userId, int $page): array{tickets: list<array>, page: int, pages: int, total: int}`.

  Constants: `ADMIN_PAGE_SIZE = 20`, `PROFILE_PAGE_SIZE = 10`. `AdminTicketsPage::allTickets()` and its per-status merge go away.

- [ ] **Step 1:** Write failing unit tests with the SQLite fixture:
  - Admin: insert 45 tickets of mixed statuses for 2 users. `adminList('all', 1)` → 20 rows, newest first, `pages 3`, `total 45`. Page 3 → 5 rows. Page 0 and page 99 clamp to 1 and 3. `adminList(null, 1)` counts only `moi` + `dang_xu_ly`. `adminList('xyz', 1)` gives empty, page 1 of 1.
  - Profile: insert 23 tickets for user A and 2 for user B. `profileList(A, 3)` → 3 rows, `pages 3`. User B's tickets never appear in A's list.
  - Pages: the admin page renders `Trang 2/3` with `Trước` and `Sau` links, and those links keep `status`. The profile renders `ticket_page` links that keep the order filter param.
  - The tool `listForUser` still returns at most 5.
- [ ] **Step 2:** Run the tests. They fail.
- [ ] **Step 3:** Implement the repository, service, handlers and templates. Escape everything output, as the templates already do.
- [ ] **Step 4:** Run the following:
  - `scripts/dev/php vendor/bin/phpunit --testsuite=Unit`;
  - `SKIP_SNAPSHOT=1 scripts/dev/gate.sh` → `GATE PASSED`;
  - rebuild `app`;
  - `scripts/dev/smoke.sh` → 0 FAIL. Update the `smoke.sh` and `web_snapshot.php` ticket scenarios if they assert the old markup.
- [ ] **Step 5:** Commit `feat(support): paginate tickets (admin 20 per page, profile 10)`.
