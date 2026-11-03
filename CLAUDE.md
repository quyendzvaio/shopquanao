# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Fashion shop (PHP 8.2 + MariaDB + Docker Compose) with a LangGraph chatbot (`chat-graph/`, TypeScript). The LLM picks tools, only from the 9 read-only shop tools; PHP is the only place with SQL; the `chat-graph` policy middleware enforces the hard rules (checkout refusal, tool allow-list, call budget, grounded cards and prices). Spec: `docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md` (the outfit/free-shipping tools: `docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md`).

## Commands

The host has no PHP; `scripts/dev/php` and `scripts/dev/composer` run them in Docker.

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit              # hermetic (SQLite fixtures)
scripts/dev/php vendor/bin/phpunit tests/unit/service/outfit/OutfitBuilderTest.php   # single file; or --filter=testName
scripts/dev/test-db.sh && scripts/dev/test-integration.sh         # shop_test in Compose MariaDB
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/composer dump-autoload                                # after adding a class under src/
npm --prefix mcp-server test && npm --prefix mcp-server run build
npm --prefix chat-graph ci && npm --prefix chat-graph test && npm --prefix chat-graph run build   # needs mcp-server installed (chat-graph compiles ../mcp-server/src); test = scripted LLM, no network
docker compose config --quiet
scripts/dev/smoke.sh                                              # HTTP smoke incl. 404 on source paths
```

Agent eval: the CI layer is the `chat-graph` node suite above (scripted LLM). The manual layer is `chat-graph/eval` against a real LLM (target >= 90%): see `.claude/rules/eval.md`.

Local bring-up: `cp .env.example .env`, fill `LLM_*` + `MCP_SERVICE_TOKEN` (and `CHAT_GRAPH_TOKEN` for production; unset locally = a shared dev default), `docker compose up -d --build`, then the migration gate `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`. Migrations never run on web requests.

## Layout

- `public/` is the only web root: storefront pages, `admin/`, `assets/`, and the REST front controller `public/api/index.php`.
- `src/` holds all PHP code (Composer **classmap**, no namespaces), by responsibility: `app/api/` (routes table `routes.php`, controllers in `controllers/`, `support/ChatStreamProtocol`), `app/web/` (one handler per storefront page, `admin/` for admin pages; `public/*.php` entry files only call them), `agent/` (`HarnessConfig` = the only reader of chatbot env/config; `ChatbotService` = facade, `ChatGraphClient`; `AgentFactory::executeTool` = the MCP bridge entry), `harness/` (what is left of the old pipeline: `tools/` and `memory/`, see `src/harness/README.md`), `service/` (business logic: catalog, cart, order, size, outfit, freeship, knowledge, user, review, admin), `repository/` (the only home of SQL), `model/`, `core/` (bootstrap, `config/` = `ServiceConfig`/`AppConfig`, cache, `database/MigrationRunner`, observability). New REST endpoints are registered in `src/app/api/routes.php`.
- Layer rule: `app -> agent/service/model/core`, `agent -> harness/service/repository/model/core`, `service -> repository/model/core`, `repository -> model/core`; SQL only in `repository/` (permanent exceptions: `MigrationRunner` and the two Langfuse outbox files), env reads only in `agent/` and `core/` (`core/config/` for app settings). `tests/unit/ArchitectureTest.php` enforces it with no legacy allowlist: fix the code, not the test. Per-layer how-tos: `src/app/README.md`, `src/service/README.md`, `src/repository/README.md`.
- `templates/partials/` page header/footer/chat widget, `templates/pages/` page views (`admin/` for admin); `database/schema.sql` + `database/migrations/*.sql` (dated, never edit applied ones).
- Nginx edge-caches public catalog GETs for 60s (`docker/nginx/default.conf`); stale reads after DB edits are expected.

## Chatbot architecture

```
browser -> POST /api/chatbot/stream (SSE) -> nginx -> app (PHP): session, origin, length, rate limit, history/memory from MariaDB
  -> ChatbotService (src/agent facade) -> POST http://chat-graph:8090/turn (NDJSON, header X-CHAT-GRAPH-TOKEN)
  chat-graph (Node 22, LangChain.js createAgent on LangGraph):
    policy_in (CheckoutGuardrail: refusal, no LLM) -> agent <-> tools (LLM picks tools; budget, allow-list, grounding middleware)
      -> policy_out (CardPolicy, price/stock grounding; sold-out turn: the model's answer must name the sold-out item + an alternative, else the fixed question) -> END
    tools = mcp-server createMcpServer in-process (InMemoryTransport) -> POST app:8080/api/internal/mcp -> ToolApplicationService
  <- delta / cards / complete events; PHP re-filters cards with ChatStreamProtocol and persists the turn
```

- `chat-graph` fails closed: LLM down, timeout or any graph error answers the fixed busy text (`response_type=busy`); there is no PHP fallback pipeline. It is private (compose `expose` only, nginx does not route it) and authed with `CHAT_GRAPH_TOKEN` (constant-time compare; refuses empty/`change-me`). `app` does not depend on it; it depends on `app` being healthy.
- Node env: `chat-graph/src/config.ts` is the only reader for the graph itself; the imported `mcp-server/src/config.ts` separately reads `SHOP_INTERNAL_URL`, `MCP_SERVICE_TOKEN`, `MCP_REQUEST_TIMEOUT_MS`. Tool contracts: `ToolDefinitionCatalog` (PHP allow-list) and `mcp-server/src/tools.ts`; `chat-graph/src/policies/allowList.ts` lists the 9 tools. None is a cart or order-writing tool.
- The old deterministic PHP pipeline (intent, planning, evidence, loop, response, llm, middleware, turn, `llm_providers/`, `config/harness.php`, `config/chatbot_tools.php`) was deleted with the migration to `chat-graph`; `src/harness/` keeps only `tools/` (`ToolDefinitionCatalog`, `ToolApplicationService`) and `memory/` (conversation store, session context).
- **UC-A `build_outfit`**: `products.style_tags` (1-3 of the 10 `StyleTag::ALL` tags, curated in migration `2026_10_15_product_style_tags.sql`) -> `OutfitBuilder` composes top+bottom or dress (+ accessory) within budget, size and the one-statement-piece palette rule. Ranking ties: with a budget the higher total wins, without one the lower (accessory ties too), then lower ids.
- **UC-C `suggest_freeship_items`** + proactive nudge: `FreeShippingGapAdvisor` picks in-stock items with the smallest overshoot of `FREE_SHIPPING_THRESHOLD`; the nudge's last cart fingerprint is stored in `chat_session_memory.slots`.
- MCP is private: nginx 404s `/api/internal/mcp`; it is authed by `X-MCP-SERVICE-TOKEN` (`hash_equals`).
- Browser chat = `POST /api/chatbot/stream` as Server-Sent Events read by the widget with fetch (no WebSocket, no stream service). `ChatStreamProtocol` owns the SSE framing, the card field allow-list, and the same-origin + max-length guard shared with `POST /api/chatbot`, which is the widget's fallback when the stream fails before any text.

## Hard rules

- Never auto-checkout, add to cart, or take payment from the chatbot (the `chat-graph` checkout policy refuses without calling the LLM; no write tool exists).
- Product cards come only from the private catalog: a card must come from a tool result of the same turn and pass the `chat-graph` card/grounding policy, then PHP's `ChatStreamProtocol` allow-list.
- `.env`, reports, model caches and local DB artifacts stay out of git.
- State-changing web actions are POST with a CSRF token (`src/core/http/Csrf.php`); never a GET link. Login, register and logout are the exceptions.
- Re-adding a product to the cart without a size keeps that line's size (web and API, `CartService::add`); a new line defaults to `S`.
- An env admin (no real `user_id`) cannot add to cart or check out.
- The MCP bridge only runs tools listed in `ToolDefinitionCatalog` and `chat-graph` only exposes the 9 allow-listed tools, so cart and checkout tools exist nowhere.
- Web checkout and add-to-cart go through `OrderService`/`CartService` like the API: checkout locks stock, saves the size and writes status `Chờ xử lý`. Orders already stored as `pending` are not migrated.

## Fixing failures (eval cases, bugs)

- A fix found while investigating one failing case is only a provisional answer to that case. Before adopting it, check what it does to the other cases and paths it touches (other eval groups, other tools, the prompt as a whole, other search terms), and say what you checked. Do not adopt a fix that makes other cases worse.
- Prefer a general fix at the root cause over a case-specific patch: give the model the data or tool it is missing rather than adding a keyword rule, a special branch or a prompt line for one message. A small case-specific fix is acceptable only when it leaves the rest of the system unaffected and still improves results overall; propose it with that reasoning and wait for the user's approval before applying it.

## Rules index (`.claude/rules/`)

| File | Scope |
| --- | --- |
| `architecture.md` | Runtime services, pipeline internals, MCP contract |
| `database.md` | Schema, migrations, `MigrationRunner` |
| `testing.md` | Suites, fixtures, eval, CI gates |
| `eval.md` | Two-layer chatbot eval (CI scripted LLM, manual real LLM) |
| `observability.md` | Langfuse profile, trace outbox, secrets |
| `deployment.md` | Compose, CI/CD deploy gate |
