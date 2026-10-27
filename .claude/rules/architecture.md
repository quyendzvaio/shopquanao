# Runtime architecture

## Compose services

| Service | Role |
| --- | --- |
| `nginx` | Public gateway + chatbot rate limit |
| `app` | PHP-FPM + nginx (serversideup image): storefront, REST API, chatbot, MCP client |
| `db` | MariaDB 10.11 |
| `redis` | Shared cache (chat rate limit, catalog cache) |
| `qdrant` | Knowledge vector store |
| `rag-ml` | Embedding + knowledge rerank |
| `reranker` | Product rerank sidecar |

App uses `${APP_IMAGE:-shop_quan_ao-app:latest}`.

## Layers

`app` (`src/app/api`, `src/app/web`) -> `agent` -> `harness` -> `service` -> `repository` -> `core`, with `model` below all of them. `app` uses `agent`/`service`/`model`/`core` (never `repository`); `service` uses `repository`/`model`/`core`; `repository` uses `model`/`core`. Web pages: `public/*.php` entry file -> `src/app/web/<Page>.php` handler -> `templates/pages/*.php` view. Env is read only in `src/agent/` and `src/core/` (app settings in `src/core/config/`: `ServiceConfig`, `AppConfig`). `tests/unit/ArchitectureTest.php` enforces all of it; the only allowlist is the permanent SQL exceptions (`MigrationRunner`, the Langfuse outbox files).

- Add a page: handler in `src/app/web/` (admin: `src/app/web/admin/`) returning a `PageResult`, a view in `templates/pages/`, a thin entry file in `public/`.
- Add an endpoint: route in `src/app/api/routes.php`, controller in `src/app/api/controllers/`, logic in a `src/service/` class.
- Add a query: a method on a `src/repository/` class; services call it, nothing else writes SQL.

## Chat pipeline internals (`src/harness/`, `src/agent/`)

`ChatbotService` (`src/agent/`) is a facade; `AgentFactory` is the only place that reads chatbot env/config (`HarnessConfig`), picks the tool transport and LLM, and builds the `MiddlewareStack` from `config/harness.php`. `TurnRunner::run()` (`src/harness/turn/`) runs one turn: loadMemory → beforeTurn → intent → loop (planning/tools/evidence) → answer (LLM via middleware) → afterTurn → persist. Component map and allowed dependencies: `src/harness/README.md`, enforced by `tests/unit/ArchitectureTest.php`.

Middleware (`config/harness.php`, run order; hooks `beforeTurn`, `wrapModelCall`, `afterTurn`): `CheckoutGuardrail` (beforeTurn, required; `CheckoutDetector` match → `unsupported_checkout` refusal, no tools), `AnswerTemplateFallback` (wrapModelCall for the streamed answer, required), `FreeShippingNudge` (afterTurn, optional), `TraceOutbox` (afterTurn, optional). With a required one missing from the config, `AgentFactory` throws a `LogicException` (`AgentFactory::REQUIRED_MIDDLEWARE`) when it builds the middleware stack / turn runner for a turn. To add one: class extending `BaseMiddleware`, an arm in `AgentFactory::makeMiddleware()`, a config entry (required: also `REQUIRED = true` and `REQUIRED_MIDDLEWARE`).

Order inside the turn:

1. `CapabilityRegistry::fromToolDefinitions()` — tools come from the gateway definitions, never hardcoded.
2. `IntentResolver` → `DeterministicIntentParser` first; `LlmInputParser`/`SemanticEntityEnricher` only enrich. Unresolved conflicts → clarification response (except a checkout request, which `CheckoutGuardrail` already refused). `unknown` intent or confidence < 0.6 → Vietnamese clarification fallback.
3. `EvidenceExecutionLoop` — runs `ToolPlanner` + `PlanValidator` + `ParallelToolExecutor` + `NoProgressDetector`; emits `decision` (`fallback` forces `response_type=fallback`). Intents include `build_outfit` (UC-A, `OutfitBuilder` over `products.style_tags`) and `freeship_gap` (UC-C, `FreeShippingGapAdvisor`); the proactive `FreeShippingNudge` (threshold `FREE_SHIPPING_THRESHOLD`=500000, max gap `FREESHIP_NUDGE_MAX_GAP`=200000) fires only on shopping intents, once per cart fingerprint.
4. `EvidenceNormalizer` + `ProductConstraintVerifier` — every product card passes constraint verification against the private catalog.
5. `ResponseGenerator` / `StreamingResponseGenerator` — grounded generation only from normalized evidence. If the streaming LLM fails or returns nothing, the required `AnswerTemplateFallback` middleware emits the validated grounded draft instead (appended after any partial deltas; `latency.streaming=template_fallback`); the turn never fails because of the LLM. The pipeline keeps its own `unsupported_checkout` branches as a second line of defence behind `CheckoutGuardrail`.

`ShadowToolGateway` wraps `McpToolGateway` + `ToolRegistry` when configured (compare transports); `CHATBOT_TOOL_TRANSPORT` env selects.

## MCP contract

- `mcp-server/` (TypeScript, stdio) spawns as a private child process of `app`. No public MCP port.
- MCP tools call back into PHP at `/api/internal/mcp` (`src/app/api/controllers/Internal/mcp.php`): POST `{operation:"tool.call", tool, arguments, user_id}`, header `X-MCP-SERVICE-TOKEN` must equal `MCP_SERVICE_TOKEN` (`hash_equals`). Nginx returns 404 for this path externally.
- Internal tools: Product / Size / Policy / Order / Outfit / Free-shipping via `ToolApplicationService`. `AgentFactory::executeTool` (the bridge `Internal/mcp.php` calls) runs only tools listed in `ToolDefinitionCatalog`, the same filter as `ToolRegistry::execute`; any other name (cart, checkout and order-list tools no longer exist anywhere) throws `InvalidArgumentException('Unknown tool: ...')`, mapped to 400.
- Auth errors map to 401, other runtime errors to 409, bad input to 400.

## Streaming boundary

`POST /api/chatbot/stream` (Server-Sent Events, read by the widget with fetch + ReadableStream) emits text tokens only AFTER the PHP validator completes, and only allow-listed private shop cards (`ChatStreamProtocol::CARD_FIELDS`). Never stream raw MCP payloads. Browser requests must be same-origin (or listed in `CHATBOT_ALLOWED_ORIGINS`) and ≤ `CHATBOT_MAX_MESSAGE_CHARS`; nginx rate limits and disables buffering. If the stream breaks before any text, the widget retries once via `POST /api/chatbot`.
