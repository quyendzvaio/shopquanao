# Runtime architecture

## Compose services

| Service | Role |
| --- | --- |
| `nginx` | Public gateway + chatbot rate limit |
| `app` | PHP-FPM + nginx (serversideup image): storefront, REST API, chat session/memory, internal MCP endpoint, `chat-graph` client |
| `chat-graph` | Node 22 LangGraph agent (private, port 8090): LLM picks shop tools (none writes), policy middleware, tools via in-process `mcp-server` |
| `db` | MariaDB 10.11 |
| `redis` | Shared cache (chat rate limit, catalog cache) |
| `qdrant` | Knowledge vector store |
| `rag-ml` | Embedding + knowledge rerank |
| `reranker` | Product rerank sidecar |

App uses `${APP_IMAGE:-shop_quan_ao-app:latest}`.

## Layers

`app` (`src/app/api`, `src/app/web`) -> `agent` -> `harness` (`tools/`, `memory/` only) -> `service` -> `repository` -> `core`, with `model` below all of them. `app` uses `agent`/`service`/`model`/`core` (never `repository`); `service` uses `repository`/`model`/`core`; `repository` uses `model`/`core`. Web pages: `public/*.php` entry file -> `src/app/web/<Page>.php` handler -> `templates/pages/*.php` view. Env is read only in `src/agent/` and `src/core/` (app settings in `src/core/config/`: `ServiceConfig`, `AppConfig`). `tests/unit/ArchitectureTest.php` enforces all of it; the only allowlist is the permanent SQL exceptions (`MigrationRunner`, the Langfuse outbox files).

- Add a page: handler in `src/app/web/` (admin: `src/app/web/admin/`) returning a `PageResult`, a view in `templates/pages/`, a thin entry file in `public/`.
- Add an endpoint: route in `src/app/api/routes.php`, controller in `src/app/api/controllers/`, logic in a `src/service/` class.
- Add a query: a method on a `src/repository/` class; services call it, nothing else writes SQL.

## Chat pipeline internals (`chat-graph/`, `src/agent/`)

`ChatbotService` (`src/agent/`) is a facade: PHP loads the session, the last 6 messages and the memory (`summary`/`slots`/`profile`), then POSTs `/turn` to `chat-graph` (`CHAT_GRAPH_URL`, header `X-CHAT-GRAPH-TOKEN`, `CHAT_GRAPH_TIMEOUT` seconds) and reads the NDJSON events `delta` / `cards` / `complete`. `HarnessConfig` stays the only PHP reader of chatbot env (`AgentFactory` is just the `executeTool` bridge). `chat-graph` is stateless between turns (no checkpointer); the graph's own env is read only in `chat-graph/src/config.ts` (the imported `mcp-server/src/config.ts` reads `SHOP_INTERNAL_URL`, `MCP_SERVICE_TOKEN`, `MCP_REQUEST_TIMEOUT_MS` itself).

Graph (`chat-graph/src/graph/`, policies in `chat-graph/src/policies/`):

1. `policy_in` (`beforeAgent`): `CheckoutGuardrail` (port of `CheckoutDetector`) ends the turn with a fixed refusal, no LLM call, no tools.
2. `agent` <-> `tools` (LangChain.js `createAgent`): the LLM chooses among the 11 tools, none of which writes (`search_products`, `get_product_detail`, `build_outfit`, `find_similar_in_stock`, `suggest_freeship_items`, `suggest_size`, `retrieve_knowledge`, `get_categories`, `get_order_status`, `draft_support_ticket`, `get_support_tickets`). Support tickets are the only write on the chat path, and only PHP writes them, after the customer confirms with a fixed phrase on the next turn; chat-graph and the LLM only have the draft and read tools. Middleware: tool allow-list, per-turn budget (`CHAT_GRAPH_MAX_TOOL_CALLS`, `CHAT_GRAPH_MAX_MODEL_CALLS`, `CHAT_GRAPH_TURN_TIMEOUT_MS`), tool-result tracking. A repeat of a tool call already made this turn (same name and arguments) is not run and costs no budget; the model gets a note to use the earlier result. The last allowed model call, or one after the tool budget is spent, gets no tools plus a note to answer now, and a tool call it writes anyway is dropped.
3. Sold-out edge (fixed code, not the LLM): a `search_products` result with `out_of_stock_ids` (or a `get_product_detail` with stock <= 0) fetches `find_similar_in_stock` and gives the model a note with the sold-out item and the alternatives; the model writes the whole answer, `policy_out` checks it names the sold-out item, says it is sold out and offers an alternative (else one retry, then the fixed question is appended). `response_type=clarification`, `offered` set by code, no cards for the sold-out item or the alternatives.
4. `policy_out`: `CardPolicy` (cards only from this turn's tool results, max 6) and grounding (prices/stock in the answer must match tool results); the answer leaves as plain text (`plainText` strips markdown, since the widget renders `textContent`). Before it, an empty final answer, or a sold-out answer missing the news, sends the agent loop back to the model once with its tools (`afterModel`).
   Support ticket: a `draft_support_ticket` result with status `draft` makes `policy_out` set `pending_ticket` on the outcome (`response_type=clarification`) and append the fixed draft summary (type, order, text) plus the fixed question `Bạn xác nhận gửi yêu cầu này cho shop không?` (once) to the model's lead-in prose. PHP (`ChatbotService`) stores the draft in `chat_session_memory.slots`; on the next turn a fixed yes phrase creates the `support_tickets` row (`SupportTicketService::create` re-checks), a no phrase or any other message drops it (an expired draft of this user answers a yes with a fixed "expired" reply and a no with the decline reply), and neither calls chat-graph for the yes/no turn. The `/api/chatbot` JSON carries `ticket_pending` and `ticket_id`.
5. Any LLM failure, timeout, budget overrun or graph error -> the fixed busy answer (`response_type=busy`). There is no PHP fallback pipeline.

The proactive free-shipping nudge (threshold `FREE_SHIPPING_THRESHOLD`=500000, max gap `FREESHIP_NUDGE_MAX_GAP`=200000) is decided in `chat-graph` from the cart fingerprint PHP sends; PHP stores the new fingerprint in `chat_session_memory.slots`.

Removed: the old deterministic PHP pipeline (`TurnRunner`, `IntentResolver`, `EvidenceExecutionLoop`, the middleware stack, `llm_providers/`, `config/harness.php`, `config/chatbot_tools.php`) was deleted in Task 10 of the migration. `src/harness/` keeps `tools/` (`ToolDefinitionCatalog`, `ToolApplicationService`, reached through `AgentFactory::executeTool`) and `memory/` (`ChatbotConversationStore`, `PdoChatbotConversationStore`, `ChatbotSessionContext`); `ChatbotMemory` moved to `src/service/chat/`. `tests/unit/ArchitectureTest.php` enforces that component table.

## MCP contract

- `mcp-server/` (TypeScript) is no longer spawned by PHP on the chat path: `chat-graph` imports `createMcpServer(principal)` (compiled into `dist/mcp-server/src`) and connects it per turn over an in-process `InMemoryTransport`, with `principal.userId` taken from the PHP request.
- MCP tools call back into PHP at `SHOP_INTERNAL_URL` = `http://app:8080/api/internal/mcp` (`src/app/api/controllers/Internal/mcp.php`): POST `{operation:"tool.call", tool, arguments, user_id}`, header `X-MCP-SERVICE-TOKEN` must equal `MCP_SERVICE_TOKEN` (`hash_equals`). Nginx returns 404 for this path externally.
- Internal tools: Product / Size / Policy / Order / Outfit / Free-shipping / Support (`draft_support_ticket` validates only, `get_support_tickets` reads) via `ToolApplicationService`. The bridge runs only tools listed in `ToolDefinitionCatalog`; any other name (cart, checkout and order-list tools do not exist anywhere) throws `InvalidArgumentException('Unknown tool: ...')`, mapped to 400.
- Auth errors map to 401, other runtime errors to 409, bad input to 400.

## Streaming boundary

`POST /api/chatbot/stream` (Server-Sent Events, read by the widget with fetch + ReadableStream) forwards text deltas from `chat-graph` only after its output policies have run, and only allow-listed private shop cards (`ChatStreamProtocol::CARD_FIELDS`, applied again in PHP). Never stream raw MCP payloads. Browser requests must be same-origin (or listed in `CHATBOT_ALLOWED_ORIGINS`) and <= `CHATBOT_MAX_MESSAGE_CHARS`; nginx rate limits and disables buffering. If the stream breaks before any text, the widget retries once via `POST /api/chatbot`. The SSE contract with the browser is unchanged by the move to `chat-graph`.
