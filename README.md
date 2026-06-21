# Fashion Shop — Agentic RAG Chatbot với Cross-Encoder Reranking

[![Docker](https://img.shields.io/badge/Docker-20.10%2B-2496ED?logo=docker)](https://docs.docker.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Python](https://img.shields.io/badge/Python-3.12%2B-3776AB?logo=python)](https://python.org)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545?logo=mariadb)](https://mariadb.org)
[![DeepSeek](https://img.shields.io/badge/LLM-DeepSeek-4F46E5)](https://deepseek.com)

> **Trợ lý ảo thông minh cho thời trang online**  
> Kiến trúc Agentic RAG với cross-encoder reranking, hybrid search (FULLTEXT + LIKE),  
> tool calling (6 tools), cache đa tầng, fallback rule-based engine.

---

## ✨ Tính năng

| Tính năng | Công nghệ | Chi tiết |
|---|---|---|
| **Agentic Reasoning** | LLM (DeepSeek) + Tool Calling | 6 tools: search, detail, size, faq, outfit, categories |
| **Hybrid Search** | FULLTEXT (Boolean) + LIKE | Từ ≥3 ký tự → FULLTEXT; từ ngắn → LIKE fallback |
| **Cross-Encoder Reranking** | BAAI/bge-reranker-v2-m3 (CPU) | Sắp xếp lại kết quả theo semantic relevance |
| **Knowledge Retrieval** | DB + LIKE search | FAQ, size_guides, outfit_suggestions |
| **Cache Multi-Tier** | File-based, TTL tunable | Atomic writes, sub-directory sharding |
| **Fallback Engine** | Rule-based (keyword + regex) | 15 intents, price extraction |
| **Conversation Memory** | MariaDB + session token | 20 messages context, cross-session |
| **Product Harvesting** | Auto-extract từ tool result | Image + price + stock + link |

---

## 🏗️ Kiến trúc

```
┌──────────────────────────────────────────────────────────────────────┐
│                         Browser (Chat Widget)                        │
│                    includes/chatbox.php (JS fetch)                   │
└──────────────────────────┬───────────────────────────────────────────┘
                           │ POST /api/chatbot
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     API Router (api/index.php)                       │
│                      Route matching → dispatch                       │
└──────────────────────────┬───────────────────────────────────────────┘
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     AgenticOrchestrator (PHP 8.2)                    │
│                                                                      │
│   ┌─────────────┐   ┌──────────────────┐   ┌───────────────────┐   │
│   │ LLM Provider │   │  ToolRegistry    │   │  ChatbotEngine    │   │
│   │ (DeepSeek)   │──▶│  (6 tools)       │   │  (fallback)       │   │
│   └─────────────┘   └────────┬─────────┘   └───────────────────┘   │
└──────────────────────────────┼──────────────────────────────────────┘
                               │ internal HTTP call
                               ▼
┌──────────────────────────────────────────────────────────────────────┐
│                       Internal API Layer                             │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │               Products API (list.php)                         │   │
│  │  FULLTEXT(name) AGAINST('+khoác*' IN BOOLEAN MODE)           │   │
│  │  OR name LIKE '%áo khoác%'                                   │   │
│  └──────────────────────┬───────────────────────────────────────┘   │
│                         │ SQL results (sorted by price)              │
│                         ▼                                            │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │              Reranker Sidecar (Python)                        │   │
│  │  BAAI/bge-reranker-v2-m3 cross-encoder                       │   │
│  │  POST /rerank { query, texts[] } → sorted_indices[]          │   │
│  │  Fallback: timeout > 2s → giữ nguyên thứ tự SQL              │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  /api/products  /api/size-guide  /api/faq  /api/outfit              │
│  /api/categories  /api/orders  /api/cart                            │
└──────────────────────────────────────────────────────────────────────┘
```

### Luồng xử lý

```
User: "tìm áo khoác dưới 500k"
                        │
    1. AgenticOrchestrator.respond(message)
                        │
    2. LLM phân tích → tool_call: search_products
       params: { search: "áo khoác", max_price: 500000 }
                        │
    3. ToolRegistry.executeSearchProducts()
       ├── Cache::getSearchResult() — hit? → return
       ├── HTTP GET /api/products?search=áo khoác&max_price=500000
       │   └── SQL: FULLTEXT + LIKE → products[]
       ├── count ≥ 5? → applyRerank(query, products)
       │   ├── Truncate items > 20 (RERANK_MAX_ITEMS)
       │   ├── HTTP POST reranker:8000/rerank { query, texts }
       │   ├── Sort products theo sorted_indices
       │   └── Fallback: timeout/error → giữ nguyên
       ├── Cache::setSearchResult() — lưu kết quả (đã rerank)
       └── return products[]
                        │
    4. LLM tổng hợp → response text + product cards
                        │
    5. Save messages → DB (chat_messages + tool_executions)
                        │
    6. Frontend render → text + product grid
```

---

## 🚀 Quick Start

### Yêu cầu

- Docker & Docker Compose v2
- API key DeepSeek (đã cấu hình sẵn trong `docker-compose.yml`)
- Tối thiểu **2GB RAM** cho reranker sidecar

### Khởi động

```bash
# Build & start tất cả services (app + db + reranker + tools)
docker compose up -d --build

# Kiểm tra trạng thái
docker compose ps

# Mở trình duyệt
open http://localhost:8090
```

### Test nhanh

```bash
# Chat với bot
curl -s -X POST http://localhost:8090/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message": "tìm áo khoác"}' | python3 -m json.tool

# Search sản phẩm (raw)
curl -s "http://localhost:8090/api/products?search=áo&limit=5" \
  | python3 -m json.tool

# Kiểm tra reranker (trực tiếp)
curl -s http://localhost:8001/health
```

---

## 🧠 Cross-Encoder Reranking

### Chi tiết triển khai

| Thành phần | Giá trị |
|---|---|
| Model | `BAAI/bge-reranker-v2-m3` (cross-encoder) |
| Runtime | Python 3.12 + FastAPI + PyTorch (CPU) |
| Container | `shop_quan_ao_reranker` |
| Port | `8001:8000` |
| RAM | ~785 MB khi loaded |

### Thresholds (`ToolRegistry.php`)

| Hằng số | Giá trị | Mô tả |
|---|---|---|
| `RERANK_MIN_RESULTS` | 5 | Số kết quả tối thiểu để kích hoạt rerank |
| `RERANK_MAX_ITEMS` | 20 | Số items tối đa gửi xuống reranker |
| `RERANK_TIMEOUT_MS` | 2000 | Timeout cho mỗi request rerank (ms) |
| Fallback | Giữ nguyên thứ tự SQL | Khi timeout hoặc lỗi kết nối |

### Latency Benchmark

| Số items | Query | Latency (avg) |
|---|---|---|
| 5 | "áo" | ~270 ms |
| 15 | "áo khoác" | ~665 ms |
| 19 | "áo" | ~1,225 ms |
| 30 | "đầm dự tiệc" | ~1,700 ms *(vượt timeout → fallback)* |

### Ví dụ so sánh

Search query: `"áo"` — 19 kết quả

```
SQL order (FULLTEXT + LIKE)          →  Reranked (cross-encoder)
──────────────────────────────────       ──────────────────────────────────
 1. Bông Tai Mạ Vàng Cao Cấp    ✗        1. Áo Polo Thể Thao Cao Cấp Đỏ  ✓
 2. Vòng Cổ Bạc 925 Tinh Xảo    ✗        2. Áo Thun Cotton Basic Trắng    ✓
 3. Quần Ống Suông Thể Thao     ✗        3. Áo Sơ Mi Linen Tay Ngắn      ✓
 4. Quần Short Jean Cạp Cao     ✗        4. Áo Len Cổ Tròn Xám            ✓
 5. Áo Len Cao Cổ Màu Đất       ✓        5. Áo Vest Blazer Công Sở        ✓
...                                      ...
19. Áo Thun Cotton Basic        ✓       19. Bông Tai Mạ Vàng Cao Cấp     ✗
```

> ✅ Cross-encoder hiểu semantic "áo" — đẩy phụ kiện/quần không liên quan xuống bottom.

---

## 🔧 Tool Registry

### Các tools hiện có

| Tool | API Endpoint | Cache TTL | Auth |
|---|---|---|---|
| `search_products` | `GET /api/products?search=&category=&min_price=&max_price=` | 5 phút | No |
| `get_product_detail` | `GET /api/products/{id}` | 5 phút | No |
| `suggest_size` | `GET /api/size-guide?height=&weight=&category_id=` | 10 phút | No |
| `get_faq` | `GET /api/faq?category=&search=` | 1 giờ | No |
| `get_outfit` | `GET /api/outfit?product_id=&search=` | 10 phút | No |
| `get_categories` | `GET /api/categories` | 24 giờ | No |

### Cache Architecture

```
/tmp/shop_cache/
├── sp|<query_hash>          → search_products    (TTL: 300s)
├── pd|<product_id>          → product_detail     (TTL: 300s)
├── faq|<query_hash>         → FAQ                (TTL: 3600s)
├── sg|<query_hash>          → size_guide         (TTL: 600s)
├── of|<query_hash>          → outfit             (TTL: 600s)
├── categories               → categories         (TTL: 86400s)

Implementation:
- Atomic write (temp file → rename)
- Sub-directory sharding (2-char hash)
- Auto-cleanup: OS /tmp
```

---

## 🗄️ Database

### Chat tables

```sql
chat_sessions (id, user_id, session_token, status, updated_at)
chat_messages (id, session_id, role, message, metadata JSON)
tool_executions (id, session_id, tool_name, arguments, result JSON, duration_ms, success)
```

### Key indexes

| Index | Table | Purpose |
|---|---|---|
| `ft_products_name` (FULLTEXT) | products | Hybrid text search |
| `idx_category_price` | products | Filter category + price |
| `idx_session_id_created` | chat_messages | Fast history loading |
| `idx_user_updated` | chat_sessions | Session lookup |

---

## 🐳 Docker Services

| Service | Image | Container | Port(s) | Depends On |
|---|---|---|---|---|
| `app` | Dockerfile (custom) | `shop_quan_ao_app` | `8090:80` | db, reranker |
| `db` | mariadb:10.11 | `shop_quan_ao_db` | `3308:3306` | — |
| `reranker` | docker/reranker | `shop_quan_ao_reranker` | `8001:8000` | — |
| `phpmyadmin` | phpmyadmin:5.2 | *(profile: tools)* | `8091:80` | db |

---

## ⚙️ Cấu hình

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `LLM_PROVIDER` | `deepseek` | LLM provider |
| `LLM_API_KEY` | *(set in compose)* | API key |
| `LLM_MODEL` | `deepseek-chat` | Model name |
| `LLM_TIMEOUT` | `60` | Request timeout (s) |
| `RERANKER_URL` | `http://reranker:8000` | Reranker sidecar URL |
| `DB_HOST` | `localhost` | Database host |
| `DB_NAME` | `shop_db` | Database name |

---

## 📡 API Endpoints

### Chat

```http
POST /api/chatbot
Content-Type: application/json
Authorization: Bearer <user_token>  # optional

{
  "message": "áo khoác dưới 500k",
  "session_token": "abc..."  # optional
}

Response:
{
  "message": "string",
  "products": [{ id, name, price, stock, image, url }],
  "session_token": "string",
  "session_id": 42
}
```

### Products

```http
GET /api/products?search=&category=&min_price=&max_price=&sort=&limit=&page=
GET /api/products/{id}
GET /api/products/{id}/reviews
GET /api/products/{id}/sizes
```

### Support

```http
GET /api/size-guide?height=&weight=&category_id=
GET /api/faq?category=&search=
GET /api/outfit?product_id=&search=
GET /api/categories
```

---

## 🧪 Testing

```bash
# Chatbot
curl -s -X POST http://localhost:8090/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message": "áo thun dưới 500k"}' | python3 -m json.tool

# Product search
curl -s "http://localhost:8090/api/products?search=áo&limit=5"

# PHPUnit
docker exec shop_quan_ao_app ./vendor/bin/phpunit

# Lint
docker exec shop_quan_ao_app ./vendor/bin/phpcs \
  --standard=PSR12 api/controllers/chatbot/

# Check reranker health
curl -s http://localhost:8001/health

# Reranker benchmark trực tiếp
curl -s -X POST http://localhost:8001/rerank \
  -H "Content-Type: application/json" \
  -d '{"query": "áo khoác", "texts": ["Áo thun", "Áo khoác da"]}'
```

---

## 📊 Monitoring

- **Tool executions**: Table `tool_executions` log mọi tool call (tool, args, duration, success)
- **Reranker latency**: `error_log("Reranker latency: ...ms for N items")`
- **Cache**: File count/size tại `/tmp/shop_cache/`
- **Container health**: Docker healthcheck cho mọi service

---

## 🔒 Security

| Risk | Mitigation |
|---|---|
| API key exposure | Environment variables, không hardcode |
| SQL injection | Prepared statements (PDO) |
| XSS | `textContent` trong widget |
| Auth | Bearer token cho user endpoints |
| Rate limiting | *(chưa implement — TODO)* |

---

## ⚡ Performance Notes

- **Reranker RAM**: ~785 MB khi model loaded (CPU-only PyTorch)
- **Latency**: ~700ms / 15 items → OK cho chatbot conversation
- **Timeout threshold**: `RERANK_TIMEOUT_MS=2000` — nếu quá, fallback về SQL order
- **Cache**: Search cache TTL=5 phút → giảm tải LLM + reranker
- **Model warmup**: Lazy load — request đầu tiên mất ~4 phút (download model ~1.1GB)

---

## 📝 License

MIT — Fashion Shop Agentic RAG Chatbot
