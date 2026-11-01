# Runtime architecture

## Compose services

| Service | Role |
| --- | --- |
| `nginx` | Public gateway + chatbot rate limit |
| `app` | PHP-FPM + nginx (serversideup image): storefront, REST API, chat session/memory, internal MCP endpoint, `chat-graph` client |
| `chat-graph` | Node 22 LangGraph agent (private, port 8090): LLM picks read-only shop tools, policy middleware, tools via in-process `mcp-server` |
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

## Chat pipeline internals (`chat-graph/`, `src/agent/`)

`ChatbotService` (`src/agent/`) is a facade: PHP loads the session, the last 6 messages and the memory (`summary`/`slots`/`profile`), then POSTs `/turn` to `chat-graph` (`CHAT_GRAPH_URL`, header `X-CHAT-GRAPH-TOKEN`, `CHAT_GRAPH_TIMEOUT` seconds) and reads the NDJSON events `delta` / `cards` / `complete`. `AgentFactory`/`HarnessConfig` stay the only PHP readers of chatbot env. `chat-graph` is stateless between turns (no checkpointer); its only env reader is `chat-graph/src/config.ts`.

Graph (`chat-graph/src/graph/`, policies in `chat-graph/src/policies/`):

1. `policy_in` (`beforeAgent`): `CheckoutGuardrail` (port of `CheckoutDetector`) ends the turn with a fixed refusal, no LLM call, no tools.
2. `agent` <-> `tools` (LangChain.js `createAgent`): the LLM chooses among the 9 read-only tools (`search_products`, `get_product_detail`, `build_outfit`, `find_similar_in_stock`, `suggest_freeship_items`, `suggest_size`, `retrieve_knowledge`, `get_categories`, `get_order_status`). Middleware: tool allow-list, per-turn budget (`CHAT_GRAPH_MAX_TOOL_CALLS`, `CHAT_GRAPH_MAX_MODEL_CALLS`, `CHAT_GRAPH_TURN_TIMEOUT_MS`), tool-result tracking.
3. Sold-out edge (fixed code, not the LLM): a `search_products` result with `out_of_stock_ids` routes to `find_alternatives` (`find_similar_in_stock`) then `ask_alternatives` (fixed question, `response_type=clarification`, no cards for the sold-out item).
4. `policy_out`: `CardPolicy` (cards only from this turn's tool results, max 6) and grounding (prices/stock in the answer must match tool results).
5. Any LLM failure, timeout, budget overrun or graph error -> the fixed busy answer (`response_type=busy`). There is no PHP fallback pipeline.

The proactive free-shipping nudge (threshold `FREE_SHIPPING_THRESHOLD`=500000, max gap `FREESHIP_NUDGE_MAX_GAP`=200000) is decided in `chat-graph` from the cart fingerprint PHP sends; PHP stores the new fingerprint in `chat_session_memory.slots`.

Legacy: `src/harness/`, `config/harness.php` and `config/chatbot_tools.php` (the old deterministic pipeline: `TurnRunner`, `IntentResolver`, `EvidenceExecutionLoop`, middleware stack) remain in the tree until Task 10 of the migration removes them; they are off the chat path. `tests/unit/ArchitectureTest.php` still enforces their component table while they exist.

## MCP contract

- `mcp-server/` (TypeScript) is no longer spawned by PHP on the chat path: `chat-graph` imports `createMcpServer(principal)` (compiled into `dist/mcp-server/src`) and connects it per turn over an in-process `InMemoryTransport`, with `principal.userId` taken from the PHP request.
- MCP tools call back into PHP at `SHOP_INTERNAL_URL` = `http://app:8080/api/internal/mcp` (`src/app/api/controllers/Internal/mcp.php`): POST `{operation:"tool.call", tool, arguments, user_id}`, header `X-MCP-SERVICE-TOKEN` must equal `MCP_SERVICE_TOKEN` (`hash_equals`). Nginx returns 404 for this path externally.
- Internal tools: Product / Size / Policy / Order / Outfit / Free-shipping via `ToolApplicationService`. The bridge runs only tools listed in `ToolDefinitionCatalog`; any other name (cart, checkout and order-list tools do not exist anywhere) throws `InvalidArgumentException('Unknown tool: ...')`, mapped to 400.
- Auth errors map to 401, other runtime errors to 409, bad input to 400.

## Streaming boundary

`POST /api/chatbot/stream` (Server-Sent Events, read by the widget with fetch + ReadableStream) forwards text deltas from `chat-graph` only after its output policies have run, and only allow-listed private shop cards (`ChatStreamProtocol::CARD_FIELDS`, applied again in PHP). Never stream raw MCP payloads. Browser requests must be same-origin (or listed in `CHATBOT_ALLOWED_ORIGINS`) and <= `CHATBOT_MAX_MESSAGE_CHARS`; nginx rate limits and disables buffering. If the stream breaks before any text, the widget retries once via `POST /api/chatbot`. The SSE contract with the browser is unchanged by the move to `chat-graph`.
