# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Fashion shop (PHP 8.2 + MariaDB + Docker Compose) with a deterministic RAG chatbot. The LLM never writes SQL and never picks tools; PHP resolves intent, plans tools from a manifest and validates every product card against the private catalog. Spec: `docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md`.

## Commands

The host has no PHP; `scripts/dev/php` and `scripts/dev/composer` run them in Docker.

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit              # hermetic (SQLite fixtures)
scripts/dev/php vendor/bin/phpunit tests/unit/service/outfit/OutfitBuilderTest.php   # single file; or --filter=testName
scripts/dev/test-db.sh && scripts/dev/test-integration.sh         # shop_test in Compose MariaDB
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/composer dump-autoload                                # after adding a class under src/
npm --prefix mcp-server test && npm --prefix mcp-server run build
docker compose config --quiet
scripts/dev/smoke.sh                                              # HTTP smoke incl. 404 on source paths
```

Agent eval (40 cases, needs shop_test): `DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS=... scripts/dev/php scripts/run_agent_eval.php` — report in `reports/eval/` (gitignored).

Local bring-up: `cp .env.example .env`, fill `LLM_*` + `MCP_SERVICE_TOKEN`, `docker compose up -d --build`, then the migration gate `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`. Migrations never run on web requests.

## Layout

- `public/` is the only web root: storefront pages, `admin/`, `assets/`, and the REST front controller `public/api/index.php`.
- `src/` holds all PHP code (Composer **classmap**, no namespaces), by responsibility: `app/api/` (routes table `routes.php`, controllers in `controllers/`, `support/ChatStreamProtocol`), `app/web/` (one handler per storefront page, `admin/` for admin pages; `public/*.php` entry files only call them), `agent/` (`AgentFactory` = the only reader of chatbot env/config; `ChatbotService` = facade), `harness/` (the agent minus the LLM, one dir per component, see `src/harness/README.md`), `service/` (business logic: catalog, cart, order, size, outfit, freeship, knowledge, user, review, admin), `repository/` (the only home of SQL), `model/`, `core/` (bootstrap, `config/` = `ServiceConfig`/`AppConfig`, cache, `database/MigrationRunner`, observability), `llm_providers/`. New REST endpoints are registered in `src/app/api/routes.php`.
- Layer rule: `app -> agent/service/model/core`, `agent -> harness/llm_providers/service/repository/model/core`, `service -> repository/model/core`, `repository -> model/core`; SQL only in `repository/` (permanent exceptions: `MigrationRunner` and the two Langfuse outbox files), env reads only in `agent/` and `core/` (`core/config/` for app settings). `tests/unit/ArchitectureTest.php` enforces it with no legacy allowlist: fix the code, not the test. Per-layer how-tos: `src/app/README.md`, `src/service/README.md`, `src/repository/README.md`.
- `templates/partials/` page header/footer/chat widget, `templates/pages/` page views (`admin/` for admin); `database/schema.sql` + `database/migrations/*.sql` (dated, never edit applied ones).
- Nginx edge-caches public catalog GETs for 60s (`docker/nginx/default.conf`); stale reads after DB edits are expected.

## Chatbot architecture

```
ChatbotService (src/agent facade) -> TurnRunner::run(), one turn:
  loadMemory -> beforeTurn middleware (CheckoutGuardrail, required)
  -> IntentResolver (DeterministicIntentParser first; LLM only enriches)
  -> EvidenceExecutionLoop: ToolPlanner + PlanValidator from config/chatbot_tools.php
  -> tool gateway: McpToolGateway (mcp-server stdio child -> /api/internal/mcp) or ToolRegistry (in-process)
       both execute through ToolApplicationService
  -> EvidenceNormalizer + ProductConstraintVerifier
  -> ResponseGenerator (template) / StreamingResponseGenerator (streamed, non-template turns only: LLM rewrite of the grounded draft; every model call
       goes through MiddlewareModel; the required AnswerTemplateFallback middleware emits the grounded draft on any
       LLM failure, latency.streaming=template_fallback)
  -> afterTurn middleware: FreeShippingNudge (UC-C proactive line, once per cart state), TraceOutbox -> persist
```

- Middleware (`config/harness.php`, order = run order): `CheckoutGuardrail` and `AnswerTemplateFallback` are required (`AgentFactory::REQUIRED_MIDDLEWARE`; without them `AgentFactory` throws a `LogicException` when it builds the middleware stack / turn runner for a turn); `FreeShippingNudge` and `TraceOutbox` are optional. Add one = class extending `BaseMiddleware` + `AgentFactory::makeMiddleware` arm + config entry (required: also `REQUIRED = true` and `REQUIRED_MIDDLEWARE`).
- `tests/unit/ArchitectureTest.php` enforces the harness component table (`src/harness/README.md`): no env reads, no `app`/`agent`/`llm_providers` references, functions <= 80 lines.

- Adding an intent/tool = one manifest entry (`config/chatbot_tools.php`) + planner builder + validator rule + scorer facts + normalizer/answer; tool contracts live in `ToolDefinitionCatalog` and `mcp-server/src/tools.ts` (keep both in sync; `contract.test.ts` checks the list).
- **UC-A `build_outfit`**: `products.style_tags` (1-3 of the 10 `StyleTag::ALL` tags, curated in migration `2026_10_15_product_style_tags.sql`) → `OutfitBuilder` composes top+bottom or dress (+ accessory) within budget, size and the one-statement-piece palette rule. Ranking ties: with a budget the higher total wins, without one the lower (accessory ties too), then lower ids. "có <amount><unit>" is a budget only in outfit context; "ngân sách/budget <amount>" is global.
- **UC-C `freeship_gap`** + proactive nudge: `FreeShippingGapAdvisor` picks in-stock items with the smallest overshoot of `FREE_SHIPPING_THRESHOLD`; the nudge's last cart fingerprint is stored in `chat_session_memory.slots`.
- MCP is a private stdio child; nginx 404s `/api/internal/mcp`; it is authed by `X-MCP-SERVICE-TOKEN` (`hash_equals`).
- Browser chat = `POST /api/chatbot/stream` as Server-Sent Events read by the widget with fetch (no WebSocket, no stream service). `ChatStreamProtocol` owns the SSE framing, the card field allow-list, and the same-origin + max-length guard shared with `POST /api/chatbot`, which is the widget's fallback when the stream fails before any text.

## Hard rules

- Never auto-checkout, add to cart, or take payment from the chatbot (guardrail intent `unsupported_checkout`).
- Product cards come only from the private catalog and must pass `ProductConstraintVerifier`.
- `.env`, reports, model caches and local DB artifacts stay out of git.
- Web checkout and add-to-cart go through `OrderService`/`CartService` like the API: checkout locks stock, saves the size and writes status `Chờ xử lý`. Orders already stored as `pending` are not migrated.

## Rules index (`.claude/rules/`)

| File | Scope |
| --- | --- |
| `architecture.md` | Runtime services, pipeline internals, MCP contract |
| `database.md` | Schema, migrations, `MigrationRunner` |
| `testing.md` | Suites, fixtures, eval, CI gates |
| `eval.md` | Agent eval cases and how to read the report |
| `observability.md` | Langfuse profile, trace outbox, secrets |
| `deployment.md` | Compose, CI/CD deploy gate |
