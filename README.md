# Fashion Shop

Website bán quần áo (PHP 8.2, MariaDB 10.11, Docker Compose) kèm trợ lý chat AI. Chatbot là một agent LangGraph (`chat-graph/`, TypeScript): mô hình ngôn ngữ chọn công cụ trong 11 công cụ chỉ đọc của shop, còn các luật cứng do code đảm bảo: không đặt hàng hay thanh toán thay khách, thẻ sản phẩm và giá phải khớp dữ liệu thật, giới hạn số lượt gọi.

## Mục lục

- [Chức năng](#chức-năng)
- [Kiến trúc hệ thống](#kiến-trúc-hệ-thống)
- [Thiết kế cao cấp (HLD)](#thiết-kế-cao-cấp-hld)
- [Đánh giá (RAGAS & eval)](#đánh-gía-ragas--eval)
- [Chạy trên máy local](#chạy-trên-máy-local)
- [Cấu hình](#cấu-hình)
- [Kiểm thử](#kiểm-thử)
- [Phát hành và deploy](#phát-hành-và-deploy)
- [Vận hành production](#vận-hành-production)
- [Cấu trúc thư mục](#cấu-trúc-thư-mục)
- [Tài liệu liên quan](#tài-liệu-liên-quan)
- [Lưu ý bảo mật](#lưu-ý-bảo-mật)

## Chức năng

**Website:** danh mục, tìm kiếm và lọc sản phẩm (giá, size, màu, tồn kho), giỏ hàng, đặt hàng, đánh giá, trang cá nhân với lịch sử đơn, trang quản trị (tổng quan, sản phẩm, đơn hàng, người dùng, yêu cầu hỗ trợ).

**Chatbot:**

| Nhóm | Khách hỏi gì | Chatbot làm gì |
| --- | --- | --- |
| Sản phẩm | "áo polo đỏ dưới 400k" | Tìm trong catalog, trả thẻ sản phẩm có giá và tồn kho thật |
| Hết hàng | "quần baggy còn không" | Báo món đã hết, gợi ý món tương tự còn hàng |
| Size | "cao 1m65 nặng 55kg mặc size gì" | Tư vấn theo bảng size |
| Phối đồ (UC-A) | "800k, đồ đi phỏng vấn" | 1-2 set còn hàng, đúng size, trong ngân sách, theo 10 tag phong cách (`products.style_tags`) |
| Free ship (UC-C) | "mua thêm gì để được free ship" | Gợi ý món bù cho đủ ngưỡng 500.000đ; tự nhắc một lần khi giỏ còn thiếu tối đa 200.000đ |
| Chính sách | "đổi trả thế nào", "phí ship" | Trả lời từ kho tài liệu (RAG: Qdrant + rerank) |
| Đơn hàng | "đơn của mình đâu" | Tra đơn của chính khách đã đăng nhập |
| Yêu cầu hỗ trợ | "áo nhận bị rách, đổi giúp" | Soạn nháp; chỉ tạo yêu cầu khi khách trả lời "đồng ý" ở lượt sau |
| Chặn | "thanh toán giúp mình" | Từ chối: chatbot không có công cụ ghi giỏ hàng, đơn hàng hay thanh toán |

## Kiến trúc hệ thống

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

| Service | Vai trò |
| --- | --- |
| `nginx` | Cổng công khai (cổng 80), rate limit chatbot, cache GET catalog 60 giây |
| `app` | PHP-FPM: website, REST API, phiên chat, endpoint MCP nội bộ, client gọi `chat-graph` |
| `chat-graph` | Agent LangGraph; chỉ nội bộ, nginx không định tuyến tới |
| `db` | MariaDB 10.11 |
| `redis` | Cache dùng chung (rate limit, catalog, turn-level prompt cache) |
| `qdrant` | Vector store cho tài liệu chính sách |
| `rag-ml` | Embedding và rerank cho tài liệu |
| `reranker` | Rerank kết quả tìm sản phẩm |
| `langfuse-*` | Tracing (profile `observability`, không bật mặc định) |

### Nguyên tắc chính

- SQL chỉ nằm trong `src/repository/`. LLM không viết SQL và không có công cụ ghi; yêu cầu hỗ trợ là thứ duy nhất được ghi từ luồng chat, và do PHP ghi sau khi khách xác nhận.
- Chatbot hỏng (LLM lỗi, quá thời gian, vượt ngân sách) thì trả câu "Hệ thống đang bận" cố định (`response_type=busy`), không có đường dự phòng nào khác.
- Câu trả lời được stream từng câu, mỗi câu đã qua kiểm tra; khi câu trả lời cuối khác phần đã gửi, widget nhận một sự kiện `replace`.
- `/api/internal/mcp` trả 404 từ bên ngoài; bên trong được bảo vệ bằng `X-MCP-SERVICE-TOKEN`.
- Thứ tự phụ thuộc giữa các lớp PHP (`app -> agent -> harness -> service -> repository -> core`) được `tests/unit/ArchitectureTest.php` kiểm tra.

## Thiết kế cao cấp (HLD)

### 1. Luồng chat

```
Browser ──POST /api/chatbot/stream──▶ nginx ──▶ app (PHP)
                                              │ phiên, origin, độ dài, rate limit (nginx)
                                              │ lưu tin nhắn vào MariaDB
                                              ▼
                                    POST http://chat-graph:<port>/turn   (mạng nội bộ Compose)
                                                   │
                                     chat-graph (Node 22, TypeScript)
                                     LangGraph + createAgent + middleware policy + LLM
                                                   │ tool qua MCP (mcp-server, trong tiến trình)
                                                   ▼
                                  app (PHP) /api/internal/mcp ── ToolApplicationService ── service/repository ── MariaDB
```

- **PHP giữ phần dữ liệu:** storefront, REST, DB, các tool (`ToolApplicationService`, `ToolDefinitionCatalog`, `/api/internal/mcp`), phiên chat và lịch sử tin nhắn.
- **Node (`chat-graph`) lo phần "nghĩ":** graph, LLM, các policy. Node lấy tool qua `mcp-server` có sẵn.
- Service mới `chat-graph` trong `docker-compose.yml`: chỉ nằm trong mạng nội bộ, không có cổng public và nginx không định tuyến tới nó. PHP gọi tới bằng header `X-CHAT-GRAPH-TOKEN`, so sánh kiểu hằng thời gian (constant-time). Token là biến env `CHAT_GRAPH_TOKEN`, không dùng chung `MCP_SERVICE_TOKEN`.

### 2. Graph

```
START
  └▶ policy_in            (beforeAgent: CheckoutGuardrail → END với câu từ chối cố định, không gọi LLM)
  └▶ agent ⇄ tools        (createAgent: LLM chọn tool; middleware giới hạn số lần, lọc tool, kiểm tra kết quả)
        │
        ├─ [cạnh: hết hàng] ─▶ find_alternatives ─▶ ask_alternatives ─▶ END
        │
        └─ [còn lại] ─▶ policy_out (kiểm tra câu trả lời và thẻ) ─▶ END
```

- **Cạnh "hết hàng"** chạy khi trong lượt có một kết quả `search_products` mà `out_of_stock_ids` khác rỗng. Ngay khi gặp kết quả đó, graph rẽ nhánh: tìm mẫu thay thế bằng code cố định (`find_similar_in_stock`). LLM nhận dữ liệu (mẫu đã hết, các mẫu thay thế) và tự viết cả câu trả lời; code kiểm tra câu đó, sai thì dùng câu hỏi cố định.
- **`find_alternatives`** gọi tool mới `find_similar_in_stock` với `product_id` là mẫu đã hết. Node này không gọi LLM.
- **`ask_alternatives`** giữ câu LLM viết khi câu đó đạt kiểm tra; nếu không, dùng mẫu câu cố định.

### 3. Policy out (`policy_out`)

`CardPolicy` (thẻ chỉ từ kết quả tool của lượt đó, tối đa 6) và grounding (giá/tồn kho trong câu trả lời phải khớp kết quả tool). Câu trả lời ra dạng plain text (`plainText` strips markdown vì widget render `textContent`). Trước đó, câu trả lời trống, câu trả lời hết hàng thiếu tin tức, câu có raw tool-call markup, hoặc câu hỏi xác nhận ticket không có bản nháp hợp lệ → gửi vòng agent lại model một lần với công cụ (`afterModel`).

### 4. Hỗ trợ ticket

Khi `draft_support_ticket` trả về `draft`, `policy_out` đặt `pending_ticket` và gộp bản tóm tắt cố định (loại, đơn, nội dung) cộng câu hỏi `Bạn xác nhận gửi yêu cầu này cho shop không?` vào lead-in của model. PHP lưu nháp vào `chat_session_memory.slots`; lượt sau nếu khách nói "đồng ý" PHP tạo hàng trong `support_tickets` (không qua chat-graph). Nếu nói "thôi" hoặc tin nhắn khác thì hủy nháp.

### 5. Xử lý sự cố

Bất kỳ lỗi LLM, timeout, vượt ngân sách hay lỗi graph → câu trả lời cố định "Hệ thống đang bận" (`response_type=busy`). Không có PHP fallback pipeline.

### 6. Free-shipping nudge

Quyết định trong `chat-graph` từ cart fingerprint PHP gửi; ngưỡng `FREE_SHIPPING_THRESHOLD`=500000, khoảng tối đa `FREESHIP_NUDGE_MAX_GAP`=200000. PHP lưu fingerprint mới vào `chat_session_memory.slots`.

### 7. Bo regla đã bỏ

Luồng PHP cũ (`TurnRunner`, `IntentResolver`, `EvidenceExecutionLoop`, middleware cũ, `llm_providers/`, `config/harness.php`, `config/chatbot_tools.php`) đã bị xóa khi chuyển sang `chat-graph`. `src/harness/` chỉ giữ `tools/` và `memory/`.

## Đánh giá (RAGAS & eval)

Chatbot được đánh giá ở hai lớp:

| Lớp | Lệnh | LLM | Khi nào |
| --- | --- | --- | --- |
| CI | `npm --prefix chat-graph test` | Giả lập, không cần mạng | Mỗi lần push |
| Thủ công | `npm --prefix chat-graph run eval` | LLM thật trong `.env` | Trước khi phát hành, sau khi đổi prompt, policy hoặc model; mục tiêu ≥ 90% của 57 case |

### Kết quả RAGAS gần đây (9 kịch bản, mô hình cũ `-v4-flash-sale`)

| Chỉ số | Giá trị | Ghi chú |
| --- | --- | --- |
| Deterministic passed | 9 / 9 | 100% các kịch bản chính sách |
| Answer relevancy | 0.51 | Đánh giá trên 9 lượt |
| Faithfulness | 0.72 | Đánh giá trên 6 lượt có bối cảnh |
| Context precision | 0.99 | Bao gồm tài liệu RAG + bằng chứng sản phẩm/đơn hàng |
| Context recall | 0.83 | — |
| Độ trễ trung bình | ~26 ms | p95 ~38 ms |

Bằng chứng RAGAS dùng embeddings từ `rag-ml` (`bkai-foundation-models/vietnamese-bi-encoder`), câu hỏi answer_relevancy được sinh bằng tiếng Việt.

### Bộ eval

57 case trong `chat-graph/eval/cases.jsonl` chia thành các nhóm: `s1_regression`, `outfit`, `freeship`, `guardrail`, `unrelated`, `multi_intent`, `sold_out`, `catalog`, `knowledge`, `order`, `memory`, `ticket`. Chạy: `npm --prefix chat-graph run eval -- --group=<nhóm>` hoặc cả bộ; báo cáo ra `reports/eval/` (gitignored, không commit). Thay đổi trên luồng chat phải có unit test đi kèm; eval thủ công không thay cho test.

## Chạy trên máy local

Cần Docker Engine với Compose v2. Máy host không cần PHP hay Node: các lệnh PHP chạy trong container qua `scripts/dev/php`.

```bash
cp .env.example .env
```

Điền tối thiểu trong `.env`:

```env
LLM_BASE_URL=https://<endpoint-openai-compatible>/v1
LLM_API_KEY=<key>
LLM_MODEL=<tên model>
MCP_SERVICE_TOKEN=<chuỗi ngẫu nhiên dài>
```

Khởi động, chạy migration rồi kiểm tra:

```bash
docker compose up -d --build
docker compose run --rm --no-deps app php scripts/run_database_migrations.php
curl -fsS http://localhost/nginx-health
curl -fsS 'http://localhost/api/products?limit=1'
```

- Cổng 80 bận: `NGINX_HTTP_PORT=8092 docker compose up -d --build`.
- Migration không bao giờ chạy trong request web; luôn chạy bằng lệnh trên (có khóa, ghi lại trong bảng `_migrations`).
- Index lại tài liệu chính sách sau khi sửa `knowledge/`: `docker compose exec -T app php scripts/ingest_knowledge.php`.
- Chỉ build lại app và chatbot: `docker compose build app chat-graph && docker compose up -d --no-deps app chat-graph`.
- Tắt mà vẫn giữ dữ liệu: `docker compose stop`. Không dùng `docker compose down` trên máy có dữ liệu cần giữ.

## Cấu hình

Toàn bộ cấu hình nằm trong `.env` (không commit). Danh sách đầy đủ có trong [`.env.example`](.env.example). Các biến hay cần chỉnh:

| Biến | Ý nghĩa |
| --- | --- |
| `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL` | Nhà cung cấp LLM (API tương thích OpenAI) |
| `MCP_SERVICE_TOKEN` | Token giữa `chat-graph` và endpoint MCP của PHP |
| `CHAT_GRAPH_TOKEN` | Token giữa PHP và `chat-graph`; để trống thì dùng giá trị mặc định cho dev |
| `CHATBOT_ALLOWED_ORIGINS`, `CHATBOT_MAX_MESSAGE_CHARS` | Origin được gọi chatbot, độ dài tin nhắn tối đa |
| `FREE_SHIPPING_THRESHOLD`, `FREESHIP_NUDGE_MAX_GAP` | Ngưỡng free ship (500000) và khoảng thiếu để nhắc chủ động (200.000đ) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Tài khoản quản trị |
| `APP_ENV` | `production` mới gửi trace lên Langfuse |
| `PROMPT_CACHE_TTL` | TTL (giây) của turn-level prompt cache cho khách vãng lai; `0` tắt cache; mặc định 1800 (30 phút) |
| `LANGFUSE_*` | Xem [Tracing với Langfuse](#tracing-với-langfuse) |

Giờ hệ thống là giờ Việt Nam: `docker/php.ini` đặt `date.timezone=Asia/Ho_Chi_Minh`, và `config/db.php` đặt cùng múi giờ cho phiên MariaDB.

## Kiểm thử

```bash
scripts/dev/gate.sh                                      # toàn bộ cổng kiểm tra local (bên dưới) + so sánh web snapshot
scripts/dev/php vendor/bin/phpunit --testsuite=Unit      # unit, không cần DB (fixture SQLite)
scripts/dev/test-db.sh && scripts/dev/test-integration.sh # integration trên DB shop_test
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
npm --prefix mcp-server ci && npm --prefix mcp-server test && npm --prefix mcp-server run build
npm --prefix chat-graph ci && npm --prefix chat-graph test && npm --prefix chat-graph run build
scripts/dev/smoke.sh                                     # smoke HTTP, gồm 404/403 cho đường dẫn mã nguồn
```

## Phát hành và deploy

| Nhánh | Vai trò |
| --- | --- |
| `develop` | Nhánh tích hợp; mọi thay đổi vào qua PR, CI phải xanh và nhánh phải cập nhật theo `develop` |
| `master` | Bản đang chạy trên production; mỗi lần merge là một lần deploy |

Quy trình:

1. Tạo nhánh từ `develop`, mở PR vào `develop`, chờ CI xanh rồi squash merge.
2. Tạo PR `release/vX.Y` vào `master` có nội dung đúng bằng `develop`, chờ CI xanh rồi merge.
3. Merge vào `master` sẽ chạy job **Deploy to Production**.

CI (`.github/workflows/ci.yml`) gồm các job: Code Quality (composer validate, lint PHP, PHPStan, test và build `mcp-server` và `chat-graph`), Unit Tests, Integration Tests (import schema, chạy migration trước), Security Scan, Docker Build (`app`, `chat-graph`, `reranker`, `rag-ml`), rồi Deploy.

Job Deploy vào máy chủ qua SSH và chạy theo thứ tự:

1. Cập nhật mã nguồn.
2. Ghi lại `.env` từ GitHub secrets.
3. Build image.
4. Chờ DB và Redis healthy, chạy migration; migration lỗi thì dừng.
5. Bật stack.
6. Index tài liệu vào Qdrant.
7. Smoke: website, chatbot từ chối checkout, stream SSE.

Vì `.env` trên máy chủ được ghi lại mỗi lần deploy, **muốn đổi cấu hình production thì đổi GitHub secret rồi chạy lại job Deploy**, không sửa tay trên máy chủ:

```bash
gh secret set LLM_MODEL --body "<model>"
# giá trị bí mật: đọc từ file thay vì gõ, để không lộ trong lịch sử lệnh
grep '^LLM_API_KEY=' .env | cut -d= -f2- | tr -d '\n' | gh secret set LLM_API_KEY
# chạy lại riêng job Deploy của lượt chạy gần nhất trên master
gh api -X POST repos/<owner>/<repo>/actions/jobs/<deploy-job-id>/rerun
```

Các nhóm secret: kết nối (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_PATH`, `DEPLOY_PORT`), cơ sở dữ liệu (`MARIADB_ROOT_PASSWORD`, `DB_PASS`), chatbot (`LLM_*`, `MCP_SERVICE_TOKEN`, `CHAT_GRAPH_TOKEN`), Langfuse (`LANGFUSE_*`). Không có secret nào được để trong mã nguồn hay image.

## Vận hành production

Kiểm tra nhanh sau deploy:

```bash
curl -fsS http://<production host>/nginx-health
curl -fsS 'http://<production host>/api/products?limit=1'
```

Chatbot trả "Hệ thống đang bận" cho mọi câu thường do nhà cung cấp LLM. Thứ tự kiểm tra:

1. Số dư tài khoản LLM. Nhà cung cấp hiện tại trả `429` khi ví dưới mức tối thiểu, và khi đó chỉ cho một request tại một thời điểm.
2. Các secret `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL` có khớp với giá trị đang chạy được hay không.
3. Log trên máy chủ: `docker compose logs chat-graph | grep "turn failed"`.

### Tracing với Langfuse

Langfuse tự host, chạy trong profile `observability`. Deploy chỉ bật profile này khi secret `LANGFUSE_ENABLED=true`. `chat-graph` gửi trace khi đồng thời có `APP_ENV=production`, `LANGFUSE_ENABLED=true` và cặp key của project. Nội dung tin nhắn không được ghi vào trace, trừ khi bật `LANGFUSE_TRACE_CONTENT=true`.

Truy cập trên production:

- Giao diện ở `http://<production host>:3000`. Cổng 3000 chỉ mở cho IP được phép trong security group của máy chủ.
- `LANGFUSE_PUBLIC_URL` phải đúng bằng địa chỉ này, nếu không trang đăng nhập sẽ chuyển hướng về `localhost`.
- Tài khoản mới đăng ký tự vào project `fashion-shop-chatbot-eval` với quyền ADMIN. Sau khi người cần dùng đã có tài khoản, đặt secret `LANGFUSE_DISABLE_SIGNUP=true` và chạy lại Deploy để khóa đăng ký.
- Một lần đăng nhập giữ trong 90 ngày.

### Turn-level prompt cache (tiết kiệm chi phí LLM)

- Có sẵn qua biến `PROMPT_CACHE_TTL` (mặc định 1800s = 30 phút; `0` để tắt).
- Chỉ cache lượt chat của **khách vãng lai** (`user_id` rỗng), không stream, không có nháp ticket sống.
- Key hash từ: nội dung câu hỏi + cửa sổ lịch sử 6 lượt + tóm tắt/slots phiên. Dùng lại câu trả lời đã lưu mà không gọi LLM/graph.
- Không cache: lượt đã đăng nhập (xem đơn/ticket/giỏ), lượt stream, lượt nudge free-ship, busy/từ chối/hỏi lại.
- Cache luôn lưu cả hai dòng hội thoại và refresh tóm tắt, nên phiên không bị "mất trí nhớ" khi hit.

### Theo dõi chi phí

- Mỗi lượt gọi LLM được log token (`prompt_tokens`, `completion_tokens`, `total_tokens`) và thời gian qua `UsageLogger` trong `chat-graph/src/turn.ts`.
- Dùng số liệu này để so sánh trước/sau khi bật prompt cache và điều chỉnh `PROMPT_CACHE_TTL`.

Chạy Langfuse trên máy local:

```bash
docker compose --profile observability up -d langfuse-postgres langfuse-clickhouse \
  langfuse-minio langfuse-redis langfuse-web langfuse-worker
```

Lưu trữ, sao lưu và cách reset an toàn: [`docs/langfuse-observability.md`](docs/langfuse-observability.md).

## Cấu trúc thư mục

| Thư mục | Nội dung |
| --- | --- |
| `public/` | Web root duy nhất: trang storefront, `admin/`, `assets/`, front controller REST `public/api/index.php` |
| `src/app/` | `api/` (bảng route `routes.php`, controller, `ChatStreamProtocol`) và `web/` (handler từng trang) |
| `src/agent/` | `ChatbotService` (mặt tiền chatbot phía PHP), `ChatGraphClient`, `HarnessConfig`, cầu nối MCP |
| `src/harness/` | `tools/` (danh mục và thực thi công cụ) và `memory/` (lưu hội thoại) |
| `src/service/` | Nghiệp vụ: catalog, giỏ hàng, đơn hàng, size, phối đồ, free ship, tài liệu, hỗ trợ, người dùng, đánh giá, quản trị |
| `src/repository/` | Truy cập dữ liệu, nơi duy nhất có SQL |
| `src/model/`, `src/core/` | Đối tượng dữ liệu; bootstrap, cấu hình, cache, `MigrationRunner` |
| `templates/` | `partials/` (header, footer, widget chat) và `pages/` (giao diện từng trang) |
| `database/` | `schema.sql` và `migrations/` (đặt tên theo ngày, không sửa file đã chạy) |
| `chat-graph/` | Agent LangGraph: graph, policy, prompt, bộ eval (`eval/`) |
| `mcp-server/` | Định nghĩa 11 công cụ, được `chat-graph` nạp trong cùng tiến trình |
| `knowledge/` | Tài liệu chính sách cho RAG |
| `docker/`, `Dockerfile` | Cấu hình image và nginx |
| `scripts/` | Migration, index tài liệu, tạo admin; `scripts/dev/` là công cụ cho máy dev |
| `tests/` | `unit/` (gồm `ArchitectureTest`), `integration/`, `support/` |

## Tài liệu liên quan

| Tài liệu | Nội dung |
| --- | --- |
| [`docs/technical-spec.md`](docs/technical-spec.md) | Thiết kế cao cấp (HLD) của hệ thống hiện tại |
| [`docs/langfuse-observability.md`](docs/langfuse-observability.md) | Vận hành Langfuse |
| [`docs/ban-giao-production-checklist.md`](docs/ban-giao-production-checklist.md) | Checklist bàn giao production |
| [`docs/chatbot-spec.md`](docs/chatbot-spec.md) | Tối tiền chi tiết chatbot |
| [`docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md`](docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md) | Yêu cầu phần mềm của chatbot (ISO/IEC/IEEE 29148) |
| [`docs/Manual_Test_Cases_Chatbot.md`](docs/Manual_Test_Cases_Chatbot.md) | Bộ kiểm thử tay cho chatbot |

`.env`, báo cáo (`reports/`), cache model và dữ liệu DB local không được đưa vào Git hay image.

## Lưu ý bảo mật

- Không in `LLM_API_KEY` hoặc bất kỳ secret nào vào chat, log hay commit.
- Ghi GitHub secret qua pipe, không gõ tay: `grep '^LLM_API_KEY=' .env | cut -d= -f2- | tr -d '\n' | gh secret set LLM_API_KEY`.
- IP máy chủ production không được để trong file tracked (dùng `<production host>` thay thế).
