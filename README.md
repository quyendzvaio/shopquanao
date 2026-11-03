# Fashion Shop — Chatbot LangGraph

Ứng dụng bán quần áo chạy bằng PHP 8.2, MariaDB và Docker Compose. Chatbot là agent LangGraph (`chat-graph/`): LLM chọn trong 9 tool chỉ-đọc, RAG cho chính sách, tool gọi lại PHP qua MCP nội bộ. Hai use case chính: phối set theo tag phong cách (UC-A) và gợi ý đạt free ship (UC-C). Sản phẩm hiển thị luôn lấy từ private shop catalog.

LLM không tự viết SQL; SQL chỉ nằm trong PHP (`src/repository/`). Policy middleware của `chat-graph` ép luật cứng (từ chối checkout, allow-list tool, ngân sách lượt gọi, card và giá phải khớp kết quả tool).

## Chức năng

| Nhóm | Khả năng |
| --- | --- |
| Product | Tìm kiếm, xem chi tiết, lọc giá/tồn kho/size/màu |
| Size | Tư vấn theo chiều cao, cân nặng và size guide |
| Policy RAG | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| Order | Tra cứu đơn thuộc user đã xác thực; không lộ dữ liệu user khác |
| Guardrail | Không tự checkout/thêm giỏ/thanh toán |
| UC-A Phối set | Hỏi "Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg" → 1-2 set còn hàng, đúng size, tổng ≤ ngân sách, theo tag dịp/phong cách (`products.style_tags`, 10 tag) |
| UC-C Free ship | Hỏi "mua thêm gì để được free ship" hoặc nhận gợi ý chủ động khi giỏ còn thiếu ≤ 200k so với ngưỡng 500k (mỗi trạng thái giỏ chỉ nhắc một lần) |

## Kiến trúc runtime

```text
Browser
  -> Nginx
  -> PHP ChatbotService (facade): session, giới hạn, lịch sử, memory
      -> POST chat-graph /turn (NDJSON)
          -> policy_in (CheckoutGuardrail, không gọi LLM)
          -> agent <-> tools (LLM chọn tool; budget, allow-list, grounding)
              -> mcp-server (in-process) -> POST app /api/internal/mcp
                  -> Product / Size / Policy / Order / Outfit / Free-shipping tools
          -> policy_out (CardPolicy, kiểm giá/tồn kho)
      <- delta / cards / complete; PHP lọc lại card và lưu lượt chat
```

| Service | Vai trò |
| --- | --- |
| `nginx` | Public gateway và chatbot rate limit |
| `app` | PHP-FPM + nginx, REST API, session/memory chat, endpoint MCP nội bộ, client của `chat-graph` |
| `chat-graph` | Agent LangGraph (Node 22, private): LLM chọn tool chỉ-đọc, policy middleware |
| `db` | MariaDB 10.11 |
| `redis` | Shared cache |
| `qdrant` | Knowledge vector store |
| `rag-ml` | Embedding và knowledge rerank |
| `reranker` | Product rerank sidecar |

MCP là endpoint nội bộ (`chat-graph` -> `app`), không mở public MCP port. Nginx trả `404` cho `/api/internal/mcp`; app container cũng không publish port trực tiếp.

Chat widget gọi `POST /api/chatbot/stream` và đọc phản hồi dạng Server-Sent
Events (cùng origin, không có service stream riêng). PHP chỉ stream text sau khi
validator hoàn tất và chỉ gửi private shop cards đã allow-list; không stream raw
MCP payload. Nếu LLM lỗi, `chat-graph` trả câu báo bận cố định (`response_type=busy`); nếu stream
hỏng trước khi có chữ, widget gửi lại một lần qua `POST /api/chatbot`.

## Cài đặt local

```bash
cp .env.example .env
```

Điền ít nhất:

```env
LLM_API_KEY=replace-me
LLM_BASE_URL=https://your-openai-compatible-endpoint/v1
LLM_MODEL=your-model
MCP_SERVICE_TOKEN=replace-with-a-long-random-secret
```

Khởi động:

```bash
docker compose up -d --build
docker compose ps
curl -fsS http://localhost/nginx-health
curl -fsS 'http://localhost/api/products?limit=1'
```

Migration được khóa bằng MariaDB advisory lock và theo dõi trong `_migrations`.
Để chạy gate giống production trước khi bật app:

```bash
docker compose up -d --wait db redis
docker compose run --rm --no-deps app php scripts/run_database_migrations.php
```

Nếu port 80 bận:

```bash
NGINX_HTTP_PORT=8092 docker compose up -d --build
```

Index lại knowledge khi thay tài liệu:

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

## API chatbot

```http
POST /api/chatbot
Content-Type: application/json

{
  "message": "Sản phẩm mã 50 phối với gì?",
  "session_token": "optional"
}
```

Response chỉ trả product cards của shop đã qua policy card/grounding của `chat-graph` và allow-list `ChatStreamProtocol` của PHP.

## Cấu trúc thư mục

| Thư mục | Nội dung |
| --- | --- |
| `public/` | Web root duy nhất: trang storefront, `admin/`, `assets/`, REST front controller `public/api/index.php` |
| `src/app/api/` | `routes.php` + controllers REST (`controllers/`), `support/ChatStreamProtocol` |
| `src/app/web/` | Handler cho từng trang storefront (`admin/` cho trang quản trị); `public/*.php` chỉ là entry file gọi handler, view ở `templates/pages/` |
| `src/agent/` | `ChatbotService` (facade), `ChatGraphClient`, `HarnessConfig` (nơi duy nhất đọc env chatbot), `AgentFactory::executeTool` (cầu MCP) |
| `src/harness/` | Còn hai component: `tools/` (hợp đồng và thực thi tool) và `memory/` (lưu hội thoại); xem [`src/harness/README.md`](src/harness/README.md) |
| `src/service/`, `src/model/`, `src/repository/` | Nghiệp vụ (catalog, cart, order, size, outfit, freeship, knowledge, user, review, admin), đối tượng dữ liệu, truy cập dữ liệu (nơi duy nhất có SQL); xem README trong từng thư mục |
| `src/core/` | Bootstrap, `config/` (`ServiceConfig`, `AppConfig`), cache, `database/MigrationRunner`, observability (Langfuse outbox) |
| `config/` | `db.php` (kết nối DB) |
| `tests/` | `unit/` (gồm `ArchitectureTest`), `integration/`, `support/` (fixture SQLite) |
| `templates/` | `partials/` (header/footer/chat widget) và `pages/` (view từng trang) |
| `database/` | `schema.sql` và `migrations/` (đặt tên theo ngày) |
| `mcp-server/` | Định nghĩa tool MCP (TypeScript), được `chat-graph` nạp in-process |
| `chat-graph/` | Agent LangGraph (TypeScript): LLM chọn tool chỉ-đọc, policy middleware; có image riêng |
| `scripts/` | Migration gate, eval, ingest; `scripts/dev/` là wrapper chạy PHP/Composer trong Docker |

## Kiểm thử

Máy host không cần PHP; `scripts/dev/php` và `scripts/dev/composer` chạy trong Docker.

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit             # hermetic, fixture SQLite
scripts/dev/test-db.sh && scripts/dev/test-integration.sh        # DB shop_test trong Compose
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
npm --prefix mcp-server ci && npm --prefix mcp-server test && npm --prefix mcp-server run build
docker compose config --quiet
scripts/dev/smoke.sh                                             # smoke HTTP, gồm 404/403 cho source path
```

Eval chatbot (CI): bộ test node của `chat-graph` với LLM giả lập, không cần mạng:

```bash
npm --prefix mcp-server ci && npm --prefix chat-graph ci && npm --prefix chat-graph test
```

Eval thủ công với LLM thật: `chat-graph/eval` (xem `.claude/rules/eval.md`).

### Langfuse tracing

Langfuse is self-hosted as an opt-in Docker Compose profile. Start it with:

```bash
docker compose --profile observability up -d langfuse-postgres langfuse-clickhouse \
  langfuse-minio langfuse-redis langfuse-web langfuse-worker langfuse-trace-publisher
```

The profile is intentionally separate from `app`, `rag-ml` and `reranker`; the
default `docker compose up` does not pull or start ClickHouse/MinIO. Check it:

```bash
docker compose --profile observability ps
curl -fsS http://localhost:3000/ >/dev/null && echo "Langfuse UI is ready"
```

Before starting on a new host, validate the Postgres filesystem with
`scripts/langfuse-storage-check.sh`. Storage layout, persistence semantics,
backup/recovery, and the guarded reset command are documented in
[`docs/langfuse-observability.md`](docs/langfuse-observability.md).

Open `http://localhost:3000`, create a project, then put only the generated
public/secret project keys in the ignored `.env` as `LANGFUSE_PUBLIC_KEY` and
`LANGFUSE_SECRET_KEY`. Set `LANGFUSE_ENABLED=true`,
`LANGFUSE_BASE_URL=http://localhost:3000` and
`LANGFUSE_INGESTION_URL=http://langfuse-web:3000`,
`LANGFUSE_PROJECT=fashion-shop-chatbot-eval`. Never commit these values or bake
them into an image.

Chat traces are sanitized: never raw MCP responses, cookies or secret headers.
For an isolated local sandbox the Compose defaults are sufficient; replace every
`local-only-change-me` value in `.env` before sharing the stack or exposing it
beyond localhost. Generate a 64-character encryption key with `openssl rand -hex 32`.

## CI/CD

GitHub Actions chạy:

- Composer validation, PHP lint, PSR-12 advisory và PHPStan.
- MCP `npm ci`, contract tests và TypeScript build.
- PHPUnit unit/integration, rồi agent eval 40 case sau integration test.
- Secret scan, Trivy filesystem/image scan và Docker build cho app/reranker/rag-ml.
- Deploy qua SSH cho `main`/`master`; DB/Redis được chờ healthy và migration phải PASS trước khi app khởi động.

App dùng image `${APP_IMAGE:-shop_quan_ao-app:latest}`.

## Artifacts

| File | Nội dung |
| --- | --- |
| `reports/eval/` (gitignored) | Báo cáo eval thủ công mới nhất |
| `docs/chatbot-spec.md` | Spec pipeline deterministic cũ (đã thay bằng `chat-graph`, xem `docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md`) |
| `docs/technical-spec.md` | Tổng quan kiến trúc hệ thống |
| `docs/langfuse-observability.md` | Langfuse self-hosted setup |
| `docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md` | Thiết kế UC-A / UC-C |

Reports, `.env`, model cache và local database artifacts phải giữ ngoài Git. `.gitignore`/`.dockerignore` đã loại các file này khỏi commit và production image.
