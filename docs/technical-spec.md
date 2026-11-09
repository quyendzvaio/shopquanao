# Đặc tả kỹ thuật (HLD) — Fashion Shop

Phiên bản: 3.0
Cập nhật: 2026-11-09

Tài liệu này mô tả thiết kế cao cấp (High-Level Design) của hệ thống đang chạy, sau khi luồng chat PHP deterministic đã được thay bằng agent LangGraph.

## 1. Tổng quan

Fashion Shop là website bán quần áo bằng PHP 8.2 và MariaDB, kèm trợ lý chat AI là một agent LangGraph chạy trên Node 22. Docker Compose dựng toàn bộ stack: nginx public gateway, PHP app, MariaDB, Redis, Qdrant, hai sidecar FastAPI cho RAG/reranking, và dịch vụ riêng `chat-graph` cho phần "nghĩ" của chatbot.

| Service | Vai trò |
|---|---|
| `nginx` | Public entrypoint, reverse proxy, rate limit chatbot, cache GET catalog 60 giây, unbuffered SSE |
| `app` | PHP-FPM + nginx (serversideup image): website, REST API, phiên chat, endpoint MCP nội bộ, client gọi `chat-graph` |
| `chat-graph` | Agent LangGraph (Node 22, TypeScript, cổng nội bộ 8090): LLM chọn tool, policy middleware |
| `db` | MariaDB 10.11: sản phẩm, user, giỏ, đơn, chat, bộ nhớ hội thoại, ticket |
| `redis` | Cache dùng chung: rate limit, catalog, embedding/rerank, turn-level prompt cache |
| `qdrant` | Vector store cho tài liệu chính sách |
| `rag-ml` | Embedding (`bkai-foundation-models/vietnamese-bi-encoder`) và rerank tài liệu |
| `reranker` | Rerank kết quả tìm sản phẩm |
| `langfuse-*` | Tracing (profile `observability`, chỉ bật khi secret `LANGFUSE_ENABLED=true`) |

## 2. Kiến trúc tổng thể

```text
Trình duyệt ── POST /api/chatbot/stream (SSE) ──> nginx ──> app (PHP)
                                                        │ phiên chat, origin, độ dài, rate limit,
                                                        │ lịch sử và bộ nhớ hội thoại (MariaDB)
                                                        ▼
                                  chat-graph (Node 22, LangGraph, nội bộ, cổng 8090)
                                    policy_in   từ chối checkout, không gọi LLM
                                    agent <-> tools   LLM chọn công cụ; allow-list, ngân sách, grounding
                                    policy_out  thẻ sản phẩm, giá và tồn kho phải khớp kết quả công cụ
                                                        │ công cụ = mcp-server chạy trong cùng tiến trình
                                                        ▼
                                  app: POST /api/internal/mcp (nội bộ, có token) ──> service ──> repository (SQL)
```

### 2.1 Chia việc giữa PHP và Node

- **PHP giữ phần dữ liệu:** storefront, REST API, DB, các tool (`ToolApplicationService`, `ToolDefinitionCatalog`, `/api/internal/mcp`), phiên chat và lịch sử tin nhắn.
- **Node (`chat-graph`) lo phần "nghĩ":** graph, LLM, các policy. Node lấy tool qua `mcp-server` import trong cùng tiến trình (`InMemoryTransport`), mỗi lượt một server với `principal.userId` từ request PHP.
- **`chat-graph` chỉ nằm trong mạng nội bộ Compose**, không có cổng public, nginx không định tuyến tới. PHP gọi bằng header `X-CHAT-GRAPH-TOKEN`, so sánh hằng thời gian (constant-time). Token là biến riêng `CHAT_GRAPH_TOKEN`, không dùng chung `MCP_SERVICE_TOKEN`.
- Thứ tự phụ thuộc: `chat-graph` phụ thuộc `app` healthy; `app` không phụ thuộc `chat-graph` — chat-graph chết thì PHP trả câu "hệ thống đang bận".

### 2.2 Phân lớp PHP

`app` (`src/app/api`, `src/app/web`) → `agent` → `harness` (`tools/`, `memory/`) → `service` → `repository` → `core`, với `model` dưới tất cả. SQL chỉ nằm trong `src/repository/` (ngoại lệ duy nhất: `MigrationRunner`); env chỉ đọc trong `src/agent/` và `src/core/`. `tests/unit/ArchitectureTest.php` kiểm thực toàn bộ luật này.

## 3. Luồng chat (graph LangGraph)

```
START
  └▶ policy_in            (beforeAgent: CheckoutGuardrail → END với câu từ chối cố định, không gọi LLM)
  └▶ agent ⇄ tools        (createAgent: LLM chọn tool; middleware giới hạn số lần, lọc tool, kiểm tra kết quả)
        │
        ├─ [cạnh: hết hàng] ─▶ find_alternatives ─▶ ask_alternatives ─▶ END
        │
        └─ [còn lại] ─▶ policy_out (kiểm tra câu trả lời và thẻ) ─▶ nudge ─▶ END
```

PHP (`ChatbotService`) nạp phiên, 6 tin nhắn gần nhất và bộ nhớ hội thoại (`summary`/`slots`/`profile`), POST `/turn` tới chat-graph và đọc các sự kiện NDJSON `delta` / `replace` / `cards` / `complete`. chat-graph không giữ trạng thái giữa các lượt (không checkpointer).

### 3.1 policy_in — chặn checkout

`CheckoutGuardrail` (regex cố định, port từ `CheckoutDetector`) kết thúc lượt bằng câu từ chối không thay đổi, không gọi LLM, không gọi tool.

### 3.2 agent ⇄ tools — LLM chọn công cụ

LLM chọn trong 11 tool chỉ đọc: `search_products`, `get_product_detail`, `build_outfit`, `find_similar_in_stock`, `suggest_freeship_items`, `suggest_size`, `retrieve_knowledge`, `get_categories`, `get_order_status`, `draft_support_ticket`, `get_support_tickets`. Không tool nào ghi dữ liệu; ticket là lệnh ghi duy nhất trên luồng chat và do PHP ghi sau khi khách xác nhận.

Middleware policy thực thi: allow-list tool, ngân sách mỗi lượt (`CHAT_GRAPH_MAX_TOOL_CALLS`, `CHAT_GRAPH_MAX_MODEL_CALLS`, `CHAT_GRAPH_TURN_TIMEOUT_MS`), theo dõi kết quả tool. Gọi lại đúng một tool với cùng tham số trong một lượt không chạy lại và không tốn ngân sách. Lượt gọi model cuối cùng, hoặc lượt sau khi hết ngân sách tool, bị lấy hết tool kèm ghi chú "trả lời ngay"; tool call viết thêm cũng bị bỏ.

### 3.3 Cạnh hết hàng (code cố định, không phải LLM)

Một kết quả `search_products` có `out_of_stock_ids` (hoặc `get_product_detail` với tồn kho ≤ 0) kéo `find_similar_in_stock` và đưa model ghi chú gồm món đã hết và các món thay thế. Model viết cả câu trả lời; `policy_out` kiểm tra câu đó phải nêu tên món hết hàng, báo hết và đề xuất thay thế (sai thì retry một lần, rồi ghép câu hỏi cố định). `response_type=clarification`, `offered` do code đặt, không có thẻ cho món hết hàng hay các món thay thế.

### 3.4 policy_out — kiểm tra câu trả lời

`CardPolicy` (thẻ chỉ từ kết quả tool của lượt đó, tối đa 6) và grounding (giá/tồn kho trong câu phải khớp kết quả tool); câu trả lời ra dạng plain text (`plainText` bỏ markdown vì widget render `textContent`). Trước đó, một câu trả lời trống, một câu hết hàng thiếu tin tức, một câu chứa markup gọi tool thô (token `<|`/`<｜`), hoặc một câu hỏi xác nhận ticket không có bản nháp hợp lệ, đưa vòng agent về model một lần với tool (`afterModel`). Trên lượt stream, một phần câu đã trên màn hình được điều chỉnh bằng một sự kiện `replace` khi câu cuối khác phần đã gửi.

### 3.5 Yêu cầu hỗ trợ (ticket)

Kết quả `draft_support_ticket` trạng thái `draft` khiến `policy_out` đặt `pending_ticket` (`response_type=clarification`) và ghép tóm tắt nháp cố định (loại, đơn, nội dung) cộng câu hỏi `Bạn xác nhận gửi yêu cầu này cho shop không?` (một lần) vào phần dẫn của model. PHP lưu nháp vào `chat_session_memory.slots`; lượt sau một cụm từ đồng ý cố định tạo hàng `support_tickets` (`SupportTicketService::create` kiểm lại), cụm từ từ chối hoặc tin nhắn khác bỏ nháp (nháp hết hạn 900 giây trả câu cố định), và cả hai đều không gọi chat-graph. Nếu model vẫn tự hỏi xác nhận khi không có nháp hợp lệ (và không có chuyện hết hàng), `policy_out` bỏ các câu đó, hoặc trả câu cố định `TICKET_NEED_DETAILS` khi không còn gì nghĩa. `GET /api/chatbot/history` cũng trả `ticket_pending` để widget khôi phục nút Đồng ý / Huỷ sau khi tải lại.

### 3.6 Nudge free ship chủ động

Quyết định trong `chat-graph` từ cart fingerprint PHP gửi: ngưỡng `FREE_SHIPPING_THRESHOLD`=500.000đ, khoảng thiếu tối đa `FREESHIP_NUDGE_MAX_GAP`=200.000đ. PHP lưu fingerprint mới vào `chat_session_memory.slots`, nên nudge chỉ bắn một lần mỗi thay đổi giỏ.

### 3.7 Thất bại

Bất kỳ lỗi LLM, timeout, vượt ngân sách hay lỗi graph → câu trả lời cố định "Hệ thống đang bận" (`response_type=busy`). Không có PHP fallback pipeline.

## 4. MCP contract

- `mcp-server/` (TypeScript) không còn được PHP spawn trên luồng chat: `chat-graph` import `createMcpServer(principal)` và nối mỗi lượt qua `InMemoryTransport` trong tiến trình, `principal.userId` lấy từ request PHP.
- Tool gọi ngược về PHP tại `SHOP_INTERNAL_URL` = `http://app:8080/api/internal/mcp`: POST `{operation:"tool.call", tool, arguments, user_id}`, header `X-MCP-SERVICE-TOKEN` phải bằng `MCP_SERVICE_TOKEN` (`hash_equals`). Nginx trả 404 cho đường dẫn này từ bên ngoài.
- Cầu MCP chỉ chạy tool nằm trong `ToolDefinitionCatalog`; tên khác (tool giỏ, checkout, danh sách đơn không tồn tại ở đâu cả) ném `InvalidArgumentException('Unknown tool: ...')` → 400. Lỗi xác thực → 401, lỗi runtime khác → 409, input sai → 400.

## 5. Streaming

`POST /api/chatbot/stream` (SSE, widget đọc bằng fetch + ReadableStream) chỉ chuyển tiếp chữ đã kiểm tra: `AnswerStreamer` (callback handler trên các lần chạy model của agent) cắt câu khi model viết, giữ câu đầu mỗi lần chạy model (lead-in trước tool call không bao giờ hiện), bỏ một lần chạy chuyển thành tool call, và chỉ phát một câu sau khi `hasToolCallMarkup`, `ungroundedClaims`, `asksTicketConfirmation` đều qua; một lỗi dừng stream cả lượt. Lượt hết hàng, lượt nháp ticket và lượt retry của `policy_out` không stream. Cuối lượt, phần còn lại của câu cuối gửi bằng `delta`, hoặc một `replace` khi không nối tiếp được phần đã phát; PHP vẫn kết thúc và lưu lượt (`ignore_user_abort`), widget đã đăng nhập thăm dò `GET /api/chatbot/history` để lấy câu đã lưu. Yêu cầu trình duyệt phải same-origin (hoặc nằm trong `CHATBOT_ALLOWED_ORIGINS`) và ≤ `CHATBOT_MAX_MESSAGE_CHARS`; nginx rate limit và tắt buffering.

## 6. Caching nhiều tầng

| Tầng | Cơ chế | TTL |
|---|---|---|
| Trình duyệt / CDN | HTTP cache cho GET catalog, knowledge (`CLIENT_CACHE_TTL`) | 60 giây |
| nginx edge | `proxy_cache` cho GET catalog công khai | 60 giây |
| Redis ứng dụng | Catalog, category, size guide, outfit, embedding, retrieval, rerank (`src/core/cache/Cache.php`) | 60 giây – 7 ngày theo loại |
| MariaDB buffer pool | InnoDB tự quản (`MARIADB_BUFFER_POOL_SIZE`) | — |
| Turn-level prompt cache | Câu trả lời của lượt khách vãng lai lặp lại (`src/core/cache/PromptCache.php`), key = hash(message + history + memory); không cache lượt đã đăng nhập, lượt stream, lượt có nudge hay nháp ticket sống | `PROMPT_CACHE_TTL`, mặc định 1800 giây, `0` tắt |

Prompt cache là lớp tiết kiệm chi phí LLM chính: một cache hit không gọi graph và không gọi LLM. Mỗi lần gọi model được log token (`prompt_tokens`, `completion_tokens`, `total_tokens`) và thời gian qua `UsageLogger` (`chat-graph/src/usageLogger.ts`) để đo hiệu quả trước/sau khi bật.

## 7. Đánh giá (eval và RAGAS)

Hai lớp đánh giá:

| Lớp | Lệnh | LLM | Khi nào |
|---|---|---|---|
| CI | `npm --prefix chat-graph test` (225 test, gồm eval scorer) | Giả lập, không cần mạng | Mỗi lần push |
| Thủ công | `npm --prefix chat-graph run eval` (57 case, 12 nhóm) | LLM thật trong `.env` | Trước khi phát hành, sau khi đổi prompt/policy/model; mục tiêu ≥ 90% |

Kết quả tham chiếu gần nhất (model `qwen3.5-flash-sale`, 2026-11-05): 53/57 pass (92,98%). RAGAS đo trên 9 kịch bản chính sách (thời pipeline cũ): deterministic 9/9, answer relevancy 0,51, faithfulness 0,72, context precision 0,99, context recall 0,83; embedding dùng chính `rag-ml`/`bkai-foundation-models/vietnamese-bi-encoder`. Báo cáo eval đi vào `reports/eval/` (gitignored, không commit).

## 8. Cơ sở dữ liệu

Base schema `database/schema.sql`, migration tăng dần trong `database/migrations/` (đặt tên theo ngày, không sửa file đã chạy). Runner `MigrationRunner` chỉ chạy từ `scripts/run_database_migrations.php` (không bao giờ chạy trong web request), khoá bằng MariaDB advisory lock, ghi nhận trong bảng `_migrations`. Bảng `support_tickets` (migration 2026-11-03) lưu yêu cầu hỗ trợ do chatbot soạn; chỉ `SupportTicketRepository` chạy SQL của nó.

Múi giờ: `docker/php.ini` đặt `date.timezone=Asia/Ho_Chi_Minh`; `config/db.php` đặt cùng múi giờ cho mỗi phiên MariaDB (`SET time_zone`).

## 9. CI/CD

CI (`.github/workflows/ci.yml`): Code Quality (composer validate, lint PHP, PHPStan, test + build `mcp-server` và `chat-graph`) → Unit Tests → Integration Tests (import schema, chạy migration trước) → Security Scan (quét secret cứng, Trivy fs) → Docker Build (`app`, `chat-graph`, `reranker`, `rag-ml`) → Deploy.

Quy trình phát hành: nhánh tính năng → PR vào `develop` (CI xanh, squash merge) → PR `release/vX.Y` vào `master` (nội dung bằng `develop`) → merge vào `master` chạy job **Deploy to Production** qua SSH: cập nhật mã nguồn, ghi lại `.env` từ GitHub secrets, build image, chờ DB/Redis healthy, chạy migration (lỗi thì dừng), bật stack, index tài liệu vào Qdrant, smoke (website, từ chối checkout, stream SSE).

Muốn đổi cấu hình production thì đổi GitHub secret rồi chạy lại job Deploy; không sửa tay `.env` trên máy chủ.
