# Fashion Shop

Website bán quần áo (PHP 8.2, MariaDB 10.11, Docker Compose) kèm trợ lý chat AI. Chatbot là một agent LangGraph (`chat-graph/`, TypeScript): mô hình ngôn ngữ chọn công cụ trong 11 công cụ chỉ đọc của shop, còn các luật cứng do code đảm bảo: không đặt hàng hay thanh toán thay khách, thẻ sản phẩm và giá phải khớp dữ liệu thật, giới hạn số lượt gọi.

## Mục lục

- [Chức năng](#chức-năng)
- [Kiến trúc](#kiến-trúc)
- [Chạy trên máy local](#chạy-trên-máy-local)
- [Cấu hình](#cấu-hình)
- [Kiểm thử](#kiểm-thử)
- [Phát hành và deploy](#phát-hành-và-deploy)
- [Vận hành production](#vận-hành-production)
- [Cấu trúc thư mục](#cấu-trúc-thư-mục)
- [Tài liệu liên quan](#tài-liệu-liên-quan)

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

## Kiến trúc

```text
Trình duyệt ── POST /api/chatbot/stream (SSE) ──> nginx ──> app (PHP)
                                                        │  phiên chat, origin, độ dài, rate limit,
                                                        │  lịch sử và bộ nhớ hội thoại (MariaDB)
                                                        ▼
                                  chat-graph (Node 22, LangGraph, nội bộ, cổng 8090)
                                    policy_in   từ chối checkout, không gọi LLM
                                    agent <-> tools   LLM chọn công cụ; allow-list, ngân sách, grounding
                                    policy_out  thẻ sản phẩm, giá và tồn kho phải khớp kết quả công cụ
                                                        │  công cụ = mcp-server chạy trong cùng tiến trình
                                                        ▼
                                  app: POST /api/internal/mcp (nội bộ, có token) ──> service ──> repository (SQL)
```

| Service | Vai trò |
| --- | --- |
| `nginx` | Cổng công khai (cổng 80), rate limit chatbot, cache GET catalog 60 giây |
| `app` | PHP-FPM: website, REST API, phiên chat, endpoint MCP nội bộ, client gọi `chat-graph` |
| `chat-graph` | Agent LangGraph; chỉ nội bộ, nginx không định tuyến tới |
| `db` | MariaDB 10.11 |
| `redis` | Cache dùng chung (rate limit, catalog) |
| `qdrant` | Vector store cho tài liệu chính sách |
| `rag-ml` | Embedding và rerank cho tài liệu |
| `reranker` | Rerank kết quả tìm sản phẩm |
| `langfuse-*` | Tracing (profile `observability`, không bật mặc định) |

Nguyên tắc chính:

- SQL chỉ nằm trong `src/repository/`. LLM không viết SQL và không có công cụ ghi; yêu cầu hỗ trợ là thứ duy nhất được ghi từ luồng chat, và do PHP ghi sau khi khách xác nhận.
- Chatbot hỏng (LLM lỗi, quá thời gian, vượt ngân sách) thì trả câu "Hệ thống đang bận" cố định (`response_type=busy`), không có đường dự phòng nào khác.
- Câu trả lời được stream từng câu, mỗi câu đã qua kiểm tra; khi câu trả lời cuối khác phần đã gửi, widget nhận một sự kiện `replace`.
- `/api/internal/mcp` trả 404 từ bên ngoài; bên trong được bảo vệ bằng `X-MCP-SERVICE-TOKEN`.
- Thứ tự phụ thuộc giữa các lớp PHP (`app -> agent -> harness -> service -> repository -> core`) được `tests/unit/ArchitectureTest.php` kiểm tra.

Chi tiết: [`CLAUDE.md`](CLAUDE.md), [`.claude/rules/architecture.md`](.claude/rules/architecture.md).

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
| `FREE_SHIPPING_THRESHOLD`, `FREESHIP_NUDGE_MAX_GAP` | Ngưỡng free ship (500000) và khoảng thiếu để nhắc chủ động (200000) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Tài khoản quản trị |
| `APP_ENV` | `production` mới gửi trace lên Langfuse |
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

Đánh giá chatbot có hai lớp:

| Lớp | Lệnh | LLM | Khi nào |
| --- | --- | --- | --- |
| CI | `npm --prefix chat-graph test` | Giả lập, không cần mạng | Mỗi lần push |
| Thủ công | `npm --prefix chat-graph run eval` | LLM thật trong `.env` | Trước khi phát hành, sau khi đổi prompt, policy hoặc model; mục tiêu đạt ít nhất 90% của 57 case |

Cách chạy và đọc kết quả eval: [`.claude/rules/eval.md`](.claude/rules/eval.md). Thay đổi trên luồng chat phải có unit test đi kèm; eval thủ công không thay cho test.

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
| [`CLAUDE.md`](CLAUDE.md) | Tóm tắt kỹ thuật và các luật cứng của dự án |
| [`.claude/rules/`](.claude/rules/) | Kiến trúc, database, kiểm thử, eval, observability, deploy |
| [`docs/ban-giao-production-checklist.md`](docs/ban-giao-production-checklist.md) | Checklist bàn giao production |
| [`docs/superpowers/specs/`](docs/superpowers/specs/) | Thiết kế: agent LangGraph, phối đồ và free ship, yêu cầu hỗ trợ, stream câu trả lời |
| [`docs/langfuse-observability.md`](docs/langfuse-observability.md) | Vận hành Langfuse |
| [`docs/technical-spec.md`](docs/technical-spec.md) | Tổng quan kỹ thuật |

`.env`, báo cáo (`reports/`), cache model và dữ liệu DB local không được đưa vào Git hay image.
