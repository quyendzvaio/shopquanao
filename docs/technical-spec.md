# Đặc Tả Kỹ Thuật Dự Án Fashion Shop

Phiên bản: 1.0  
Cập nhật: 2026-07-03  
Phạm vi: website bán quần áo, REST API, chatbot tư vấn bán hàng, Docker, CI/CD.

## 1. Tổng Quan

Fashion Shop là hệ thống bán hàng thời trang viết bằng PHP thuần, chạy trên Apache, dùng MariaDB làm cơ sở dữ liệu chính. Điểm trọng tâm kỹ thuật của dự án là chatbot tư vấn bán hàng dạng agentic: LLM dùng function calling để gọi các tool nội bộ như tìm sản phẩm, tư vấn size, FAQ, phối đồ và chuẩn bị thanh toán.

Hệ thống được đóng gói bằng Docker Compose với các service chính:

- `app`: PHP 8.2 + Apache, chứa website, API và chatbot.
- `db`: MariaDB 10.11, lưu dữ liệu sản phẩm, user, đơn hàng, chat và memory.
- `redis`: Redis 7 Alpine, cache kết quả tool, API và LLM response.
- `reranker`: Python FastAPI + scikit-learn, rerank kết quả tìm kiếm bằng TF-IDF char n-gram.
- `phpmyadmin`: service phụ, chỉ bật khi cần bằng profile `tools`.

## 2. Tech Stack

| Lớp | Công nghệ | Vai trò |
|---|---|---|
| Backend web | PHP 8.2, Apache | Render website PHP truyền thống và phục vụ REST API |
| API router | PHP custom router | Route `/api/*` tới controller tương ứng |
| Database | MariaDB 10.11 | Sản phẩm, user, cart, order, review, FAQ, chat session, memory |
| Cache | Redis 7, fallback file cache | Cache tool/API/LLM để giảm latency và chi phí LLM |
| LLM | DeepSeek Chat API | Reasoning, tool calling, summary memory |
| Chatbot fallback | PHP rule-based engine | Trả lời khi LLM lỗi hoặc không cấu hình |
| Reranker | FastAPI, scikit-learn, TF-IDF | Sắp xếp lại kết quả search theo độ liên quan |
| Frontend | PHP templates, CSS, JS fetch | Website và widget chatbot |
| Test | PHPUnit 11 | Unit test và integration test |
| Static tools | PHPCS, PHPStan | Style và phân tích tĩnh mức cơ bản |
| CI/CD | GitHub Actions | Lint, test, scan, Docker build, deploy SSH |
| Container | Docker, Docker Compose | Local/dev/prod runtime |

## 3. Kiến Trúc Tổng Thể

```text
Browser
  |
  | Website pages: index.php, product.php, cart.php, checkout.php, admin/*
  | Chat widget: includes/chatbox.php
  v
PHP Apache App Container
  |
  | /api/* -> api/index.php -> api/controllers/*
  |
  +-- Product/Auth/Cart/Order/Admin controllers
  |
  +-- Chatbot API
        |
        v
      AgenticOrchestrator
        |
        +-- ChatbotMemory
        |     +-- chat_session_memory
        |     +-- user_long_term_memory
        |
        +-- LLMProvider / DeepSeekProvider
        |
        +-- ToolRegistry
        |     +-- Internal API calls
        |     +-- Redis/File cache
        |     +-- Reranker sidecar
        |
        +-- ChatbotEngine fallback

MariaDB Container
Redis Container
FastAPI Reranker Container
```

### 3.1 Thành Phần Chính Trong Source

| Đường dẫn | Trách nhiệm |
|---|---|
| `api/index.php` | REST API router chính |
| `api/middleware.php` | Bearer auth và kiểm tra quyền admin |
| `api/config.php`, `config/db.php` | Kết nối DB và helper API |
| `api/controllers/products/*` | Product list/detail/review/size API |
| `api/controllers/cart/*` | Cart API |
| `api/controllers/orders/*` | Order API |
| `api/controllers/admin/*` | Admin dashboard/product/order/user API |
| `api/controllers/chatbot/index.php` | Entry point của chatbot API |
| `api/controllers/chatbot/AgenticOrchestrator.php` | Điều phối LLM, tool calling, memory, response |
| `api/controllers/chatbot/ToolRegistry.php` | Định nghĩa và execute tool/function calling |
| `api/controllers/chatbot/ChatbotMemory.php` | Conversation summary, slot memory, long-term memory |
| `api/controllers/chatbot/engine.php` | Fallback rule-based chatbot |
| `api/cache/Cache.php` | Redis-first cache, fallback file cache |
| `includes/chatbox.php` | Widget chatbot phía website |
| `docker/reranker/app.py` | FastAPI reranker service |
| `.github/workflows/ci.yml` | CI/CD pipeline |

## 4. Luồng Request API

Tất cả endpoint REST được gom qua `api/index.php`.

```text
HTTP request /api/products?search=áo+bomber
  -> api/index.php
  -> parse method + path
  -> match routing table
  -> require controller file
  -> controller xử lý DB/cache
  -> JSON response
```

Các nhóm route chính:

- Auth: `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`
- Products: `GET /api/products`, `GET /api/products/{id}`, sizes, reviews
- Cart: `GET/POST /api/cart`, `PUT/DELETE /api/cart/{id}`
- Orders: `POST /api/orders`, `GET /api/orders`, `GET /api/orders/{id}`
- Chatbot: `POST /api/chatbot`, `GET /api/chatbot/history`
- Support: `GET /api/size-guide`, `GET /api/faq`, `GET /api/outfit`
- Admin: dashboard, products, orders, users

## 5. Kiến Trúc Chatbot

Chatbot được thiết kế theo mô hình agentic workflow:

```text
User message
  -> /api/chatbot
  -> resolve session_token + user_id nếu có Bearer token
  -> ChatbotMemory::rememberUserMessage()
       - update slot memory
       - update long-term memory nếu user đã đăng nhập
  -> Nếu có LLM:
       AgenticOrchestrator::processWithLLM()
       - build system prompt + memory block + 3 message gần nhất
       - gọi DeepSeek với tool definitions
       - execute tool calls tối đa 3 turn
       - harvest product cards / redirect_url
  -> Nếu LLM lỗi hoặc không có:
       ChatbotEngine rule-based fallback
  -> save chat_messages + metadata
  -> ChatbotMemory::refreshSummary()
  -> JSON response
```

### 5.1 Conversation Summary

Short-term memory được lưu theo `chat_session_memory`, áp dụng cả khách chưa đăng nhập.

Mỗi phiên chat lưu:

- `summary`: bản tóm tắt hội thoại dạng bullet ngắn.
- `slots`: state memory dạng JSON.

Khi LLM khả dụng, summary được tạo bởi LLM với prompt nén hội thoại CSKH. Khi LLM lỗi, hệ thống fallback sang summary từ slot hiện có.

Prompt gửi cho LLM không nhồi toàn bộ lịch sử dài, mà dùng:

```text
System prompt
+ MEMORY CONTEXT
  - Conversation Summary
  - Slot Memory
  - Long-term User Memory nếu có
+ 3 message gần nhất
+ message hiện tại
```

### 5.2 Slot Memory

Slot memory lưu trạng thái ngắn hạn, dễ validate và ít token:

```json
{
  "product_type": "áo khoác bomber",
  "category_id": 1,
  "color": "đen",
  "style": "form rộng",
  "size": "M",
  "height_cm": 170,
  "weight_kg": 65,
  "budget": 500000,
  "min_price": null,
  "max_price": 500000,
  "gender": "nam",
  "occasion": null,
  "material": "kaki"
}
```

Slot được cập nhật bằng rule extraction trong `ChatbotMemory::extractSlots()`, gồm:

- Loại sản phẩm: áo khoác, áo bomber, áo thun, quần jeans, váy maxi, phụ kiện...
- Giá: dưới/trên/khoảng/từ-đến, đơn vị k/nghìn/triệu.
- Size/body: chiều cao, cân nặng, size.
- Thuộc tính: màu, style, chất liệu, giới tính.

Slot được dùng để bổ sung câu thiếu ngữ cảnh. Ví dụ user đã nói "áo thun", sau đó nói "dưới 300k", tool search có thể dùng `product_type=áo thun` và `max_price=300000`.

### 5.3 Long-Term Memory

Long-term memory chỉ lưu theo tài khoản đã đăng nhập trong `user_long_term_memory`.

Các nhóm dữ liệu:

- `preferences`: thương hiệu, style, màu yêu thích, chất liệu cần tránh.
- `stable_facts`: size thường mặc, body shape, thông tin ổn định.
- `important_events`: sự kiện quan trọng cần nhớ.
- `feedback`: phản hồi tích cực/tiêu cực.
- `purchase_history`: lịch sử mua hàng lấy từ orders.

Khách chưa đăng nhập không có long-term memory, chỉ có session memory và slot memory.

### 5.4 Tool Calling

Các tool hiện có:

| Tool | Mục đích |
|---|---|
| `search_products` | Tìm sản phẩm theo keyword, category, min/max price |
| `get_product_detail` | Lấy chi tiết sản phẩm, mô tả, tồn kho, size, review |
| `suggest_size` | Tư vấn size theo chiều cao/cân nặng |
| `get_faq` | Tra cứu chính sách, giao hàng, đổi trả, thanh toán |
| `get_outfit` | Gợi ý phối đồ |
| `get_categories` | Lấy danh mục |
| `prepare_checkout` | Chuẩn bị giỏ hàng và trả `redirect_url` sang checkout |

Quy tắc quan trọng:

- Khi user hỏi sản phẩm, agent luôn gọi `search_products`, không tự suy đoán từ lịch sử.
- Keyword phải là cụm sản phẩm chính xác, ví dụ `áo bomber` được normalize thành `áo khoác bomber`.
- Khi user muốn mua/thanh toán sản phẩm cụ thể, agent gọi `prepare_checkout`.
- Nếu chưa rõ sản phẩm nào, agent hỏi lại thay vì checkout.
- Nếu chưa đăng nhập, `prepare_checkout` trả `requires_login`.
- Nội dung trả lời không in URL raw; giao diện hiển thị product card có thể bấm.

### 5.5 Product Card Response

Response chatbot có dạng:

```json
{
  "message": "Mình tìm thấy 1 sản phẩm phù hợp: Áo Khoác Bomber Kaki Đen. Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.",
  "products": [
    {
      "id": 52,
      "name": "Áo Khoác Bomber Kaki Đen",
      "price": 550000,
      "stock": 12,
      "image": "ak_bomber_03.jpg",
      "image_url": "/images/ak_bomber_03.jpg",
      "url": "/product.php?id=52"
    }
  ]
}
```

Trường `url` chỉ dùng cho card UI. Bot message không hiển thị link sản phẩm.

## 6. Search Và Reranking

### 6.1 Product Search

`api/controllers/products/list.php` xử lý search sản phẩm bằng kết hợp:

- FULLTEXT search của MariaDB.
- Phrase `LIKE`.
- Token match theo từng từ.
- Filter category/min_price/max_price.
- Sort mặc định theo giá tăng dần khi tool gọi từ chatbot.

Với truy vấn tiếng Việt compound như `áo bomber`, hệ thống normalize tại tool layer:

```text
bomber -> áo khoác bomber
áo bomber -> áo khoác bomber
quần jean -> quần jeans
áo blazer -> áo vest
```

### 6.2 Reranker Sidecar

Reranker là service Python nhẹ:

- Framework: FastAPI.
- Model: TF-IDF character n-gram 2-4 bằng scikit-learn.
- Endpoint: `POST /rerank`.
- Health: `GET /health`.
- Không cần warmup model lớn, giảm cold start.

Workflow:

```text
search_products
  -> gọi /api/products
  -> nếu số sản phẩm >= 5
       gửi tối đa 20 product names sang reranker
       nhận sorted_indices
       reorder products
  -> cache kết quả search
```

Thông số trong `ToolRegistry.php`:

- `RERANK_MIN_RESULTS = 5`
- `RERANK_TIMEOUT_MS = 2000`
- `RERANK_MAX_ITEMS = 20`

Nếu reranker lỗi hoặc timeout, hệ thống giữ nguyên thứ tự từ SQL, không làm fail chatbot.

## 7. Cache Strategy

Cache được triển khai trong `api/cache/Cache.php` theo chiến lược Redis-first:

```text
Cache::get/set
  -> thử Redis nếu extension redis và REDIS_HOST khả dụng
  -> nếu Redis lỗi hoặc không có extension, fallback file cache /tmp/shop_cache
```

Redis config trong `docker-compose.yml`:

- Image: `redis:7-alpine`
- Max memory: `128mb`
- Policy: `allkeys-lru`
- Persistence: tắt `save` và `appendonly` để ưu tiên latency

TTL chính:

| Cache | Key prefix | TTL |
|---|---|---|
| Search products | `sp` | 300 giây |
| Product detail | `pd` | 300 giây |
| Size guide | `sg` | 600 giây |
| FAQ | `faq` | 3600 giây |
| Outfit | `of` | 600 giây |
| Categories | `categories` | 86400 giây |
| LLM response | `llm` | 180 giây |

Mục tiêu:

- Giảm số lần gọi LLM.
- Giảm query DB lặp.
- Giảm latency tool calling.
- Vẫn có fallback file cache nếu Redis không hoạt động.

## 8. Database Architecture

Nhóm bảng chính:

| Nhóm | Bảng tiêu biểu | Vai trò |
|---|---|---|
| Catalog | `products`, `categories`, `product_sizes`, `size_guides` | Sản phẩm, danh mục, size |
| Commerce | `cart`, `orders`, `order_items`, `reviews` | Giỏ hàng, đơn hàng, đánh giá |
| User/Auth | `users` | Tài khoản, role, api_token, status |
| Content | `faqs`, `outfit_suggestions` | FAQ và phối đồ |
| Chatbot | `chat_sessions`, `chat_messages`, `tool_executions` | Phiên chat, message, log tool |
| Memory | `chat_session_memory`, `user_long_term_memory` | Short-term/slot/long-term memory |

Migration chatbot memory nằm ở:

- `sql/migrations/004_chatbot_memory.sql`

`ChatbotMemory::ensureSchema()` cũng tự tạo bảng memory nếu thiếu, giúp môi trường cũ không bị lỗi khi deploy.

## 9. Frontend Và Chat Widget

Website dùng PHP templates truyền thống:

- `includes/header.php`
- `includes/footer.php`
- `includes/chatbox.php`
- Các page chính: `index.php`, `product.php`, `cart.php`, `checkout.php`, `profile.php`

Chat widget:

- Gửi request bằng `fetch` tới `/api/chatbot`.
- Truyền `session_token` cho khách chưa đăng nhập.
- Nếu user đã đăng nhập, gửi Bearer token.
- Render bot message bằng `textContent` để tránh XSS.
- Render product cards từ `products[]`; card link tới `product.php?id=...`.
- Nếu API trả `redirect_url`, tự chuyển user sang checkout sau khi tool chuẩn bị giỏ hàng.
- Có sanitize hiển thị để loại link raw/emoji từ message bot hoặc history cũ.

## 10. Docker Architecture

### 10.1 App Image

`Dockerfile` dùng `php:8.2-apache`, cài:

- `pdo_mysql`
- `mysqli`
- `opcache`
- `redis` PHP extension qua PECL
- Apache rewrite

Image chỉ copy các thư mục runtime cần thiết:

- `api/`
- `config/`
- `includes/`
- `css/`
- `sql/`
- `knowledge/`
- `images/`
- PHP pages ở root
- `admin/*.php`

Cách này giảm context/layer không cần thiết và tránh cold start do copy quá nhiều file dev.

### 10.2 Compose Services

```text
app
  depends_on:
    db healthy
    redis healthy
    reranker started

db
  init schema từ sql/shop_db.sql
  fulltext config từ docker/mariadb-ft.cnf

redis
  in-memory LRU cache

reranker
  FastAPI TF-IDF

phpmyadmin
  profile tools
```

Port local:

- App: `http://localhost`
- MariaDB: `localhost:3308`
- Redis: `localhost:6379`
- Reranker: `http://localhost:8001`
- phpMyAdmin: `http://localhost:8091` khi bật profile

## 11. CI/CD Pipeline

Workflow: `.github/workflows/ci.yml`

Trigger:

- `push` vào `main` hoặc `master`
- `pull_request` vào `main` hoặc `master`

### 11.1 Job `code-quality`

Chạy trên Ubuntu:

1. Checkout source.
2. Setup PHP 8.2.
3. Validate composer.
4. `composer install`.
5. PHP lint toàn bộ file PHP, bỏ qua `vendor`, reports, test evidence.
6. PHPCS PSR-12 non-blocking.
7. Python syntax lint cho `docker/reranker/app.py`.

### 11.2 Job `unit-tests`

Phụ thuộc `code-quality`.

Chạy:

```bash
vendor/bin/phpunit --testsuite=Unit --colors=always --display-warnings
```

Unit test kiểm tra cache, chatbot engine, tool registry.

### 11.3 Job `integration-tests`

Phụ thuộc `code-quality`.

Service CI:

- MariaDB 10.11.

Flow:

1. Wait MariaDB ready.
2. Import schema bằng `sed` để bỏ `CREATE DATABASE` và `USE`.
3. `composer install`.
4. Chạy PHPUnit integration với DB thật.

### 11.4 Job `security`

Phụ thuộc `code-quality`.

Kiểm tra:

- Secret hardcoded dạng API key, GitHub token, private key.
- Hardcoded password literal trong PHP.
- Trivy filesystem scan mức HIGH/CRITICAL, non-blocking exit code.

### 11.5 Job `docker`

Phụ thuộc `code-quality`.

Build:

- App image: `shop-quan-ao:ci-${sha}`
- Reranker image: `shop-reranker:ci-${sha}`

Scan image bằng Trivy mức HIGH/CRITICAL, không block deploy do `--exit-code 0`.

### 11.6 Job `deploy`

Chỉ chạy trên branch `main` hoặc `master`, sau khi pass:

- `unit-tests`
- `integration-tests`
- `security`
- `docker`

Deploy qua SSH:

1. Cài SSH key từ GitHub Secrets.
2. Cài Docker trên server nếu thiếu.
3. Dọn disk nhẹ: prune container stopped và dangling images, giữ build cache.
4. Clone hoặc pull repo.
5. Reset về default branch từ origin.
6. Ghi `.env` từ secrets.
7. Build `reranker` và `app` theo cache-aware build.
8. `docker compose up -d --remove-orphans`.
9. Healthcheck app tối đa 120 giây.
10. Check reranker health.
11. Final image prune dangling.

Secrets cần có:

- `DEPLOY_SSH_KEY`
- `DEPLOY_KNOWN_HOSTS`
- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PATH`
- `DEPLOY_GITHUB_TOKEN` hoặc dùng `GITHUB_TOKEN`
- `LLM_API_KEY`
- `MARIADB_ROOT_PASSWORD`
- `DB_PASS`
- `PMA_PASSWORD` nếu dùng phpMyAdmin

## 12. Security Model

### 12.1 Authentication

API auth dùng Bearer token:

```text
Authorization: Bearer <api_token>
```

`authenticate()`:

- Lấy token từ header.
- Query `users.api_token`.
- Kiểm tra tài khoản tồn tại.
- Kiểm tra `status != 0`.

`requireAdmin()`:

- Gọi `authenticate()`.
- Kiểm tra `role === 'admin'`.

### 12.2 Data Safety

- API response là JSON.
- Chat UI dùng `textContent` cho message để giảm XSS.
- DB query phần chính dùng prepared statement.
- Secrets lấy từ `.env` hoặc GitHub Secrets.
- CI scan hardcoded secret.
- Docker app chạy bằng user `www-data`.

### 12.3 Lưu Ý Cần Cải Thiện

- Cần chuẩn hóa CSRF protection cho form PHP truyền thống.
- Cần rate limit endpoint chatbot để bảo vệ chi phí LLM.
- Nên thêm audit log cho admin action quan trọng.
- Nên mã hóa hoặc giới hạn retention long-term memory nếu triển khai production thật.

## 13. Observability Và Logging

Hiện tại logging chủ yếu qua:

- `error_log()` trong PHP.
- Docker logs: `docker compose logs -f app`.
- Tool execution lưu DB qua `tool_executions`.
- Reranker latency log trong `ToolRegistry::callReranker()`.

Đề xuất nâng cấp:

- Thêm request id cho chatbot turn.
- Log cache hit/miss theo sampling.
- Metric LLM latency, token usage, tool call count.
- Dashboard đơn giản cho error rate và checkout conversion từ chatbot.

## 14. Test Strategy

### 14.1 Local Test

```bash
composer install
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Integration
```

Khi chạy bằng Docker:

```bash
docker run --rm --user root -v "$PWD":/app -w /app \
  -e APP_ENV=test -e LLM_PROVIDER= \
  shop_quan_ao-app vendor/bin/phpunit --testsuite=Unit

docker run --rm --network shop_quan_ao_default --user root -v "$PWD":/app -w /app \
  -e DB_HOST=db -e DB_NAME=shop_db -e DB_USER=shop_user -e DB_PASS=shop_pass \
  -e REDIS_HOST=redis -e REDIS_PORT=6379 -e APP_ENV=test -e LLM_PROVIDER= \
  shop_quan_ao-app vendor/bin/phpunit --testsuite=Integration
```

### 14.2 Test Case Chatbot Quan Trọng

- Tìm `áo bomber` phải trả sản phẩm `Áo Khoác Bomber Kaki Đen`.
- Bot message không chứa URL raw `localhost/product.php`.
- Product card vẫn có `url` để click.
- Khi LLM tắt, fallback engine vẫn trả lời được sản phẩm, size, FAQ.
- Khi user nói mua/thanh toán sản phẩm cụ thể:
  - Đã đăng nhập: chuẩn bị cart và trả `redirect_url=/checkout.php`.
  - Chưa đăng nhập: yêu cầu đăng nhập.
  - Chưa rõ sản phẩm: hỏi lại.

## 15. Performance Notes

Các điểm tối ưu hiện có:

- Docker build cache-aware, không aggressive prune build cache trong deploy.
- App image chỉ copy runtime files cần thiết.
- Redis cache trước, file cache fallback.
- LLM response cache TTL ngắn để giảm chi phí các câu lặp.
- Reranker TF-IDF nhẹ, không cần tải model lớn, giảm cold start.
- Reranker chỉ chạy khi kết quả search đủ nhiều.
- Tool result cache giúp giảm internal HTTP + DB query.
- Conversation summary + slot memory giảm token prompt so với nhồi toàn bộ lịch sử.

Các điểm có thể tối ưu tiếp:

- Thêm invalidation cache khi admin sửa sản phẩm.
- Tách endpoint chatbot sang worker queue nếu cần streaming hoặc workload cao.
- Dùng persistent PDO connection có kiểm soát nếu traffic tăng.
- Thêm HTTP keep-alive hoặc gọi trực tiếp service class thay vì PHP-to-PHP internal HTTP cho tool.
- Thêm pagination/limit cho product card nếu catalog lớn.

## 16. Quy Ước Phát Triển

- Không đưa URL sản phẩm raw vào nội dung chatbot; dùng product cards.
- Không dùng emoji trong câu trả lời chatbot để giữ giọng chuyên viên tư vấn.
- Mỗi tool mới phải có:
  - Definition trong `ToolRegistry::registerAll()`.
  - Handler `executeX()`.
  - Cache nếu kết quả có thể tái sử dụng.
  - Unit test hoặc integration test theo mức rủi ro.
- Khi thêm bảng mới:
  - Cập nhật `sql/shop_db.sql`.
  - Thêm migration trong `sql/migrations/`.
  - Cập nhật test bootstrap nếu cần.
- Khi thay đổi Docker:
  - Kiểm tra cold start.
  - Kiểm tra build cache.
  - Không copy file dev không cần thiết vào image production.

## 17. Lệnh Vận Hành Nhanh

```bash
# Build và chạy
docker compose up -d --build

# Xem trạng thái
docker compose ps

# Xem log app
docker compose logs -f app

# Xem log reranker
docker compose logs -f reranker

# Flush Redis cache
docker compose exec -T redis redis-cli FLUSHDB

# Health app
curl http://localhost/api/products?limit=1

# Health reranker
curl http://localhost:8001/health

# Test chatbot
curl -X POST http://localhost/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message":"mình muốn tìm áo bomber"}'
```

## 18. Rủi Ro Kỹ Thuật

| Rủi ro | Tác động | Giảm thiểu hiện tại | Đề xuất |
|---|---|---|---|
| LLM API chậm/lỗi | Chatbot trả lời chậm hoặc fallback | `ChatbotEngine` fallback, cache LLM | Thêm timeout và circuit breaker rõ ràng hơn |
| Redis mất kết nối | Tăng latency | File cache fallback | Monitoring Redis health |
| Search tiếng Việt sai intent | Tư vấn sai sản phẩm | Normalize alias, FULLTEXT + LIKE + reranker | Mở rộng synonym dictionary |
| Memory lưu sai sở thích | Gợi ý không phù hợp | Slot có cấu trúc, summary ngắn | UI cho user xóa/sửa memory |
| Deploy server thiếu disk | Build fail | Giữ cache, prune nhẹ dangling image | Theo dõi disk và registry cache |
| Form PHP truyền thống thiếu CSRF | Rủi ro bảo mật | Auth token cho API | Thêm CSRF token cho form web |

## 19. Roadmap Kỹ Thuật Đề Xuất

1. Thêm cache invalidation khi admin tạo/sửa/xóa sản phẩm.
2. Thêm rate limit cho `/api/chatbot`.
3. Thêm observability cho LLM cost, latency, cache hit rate.
4. Thêm memory management UI cho user đã đăng nhập.
5. Chuẩn hóa migration runner thay vì phụ thuộc import SQL thủ công.
6. Thêm E2E test cho flow chatbot -> product card -> checkout.
7. Tách business service khỏi controller để giảm PHP-to-PHP internal HTTP trong tool.
