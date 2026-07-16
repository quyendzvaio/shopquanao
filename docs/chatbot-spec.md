# Chatbot Agentic RAG — Đặc tả kỹ thuật

> **Phiên bản**: 2.1 | **Cập nhật**: 2026-06-22
> **Dự án**: Fashion Shop — Trợ lý tư vấn bán hàng thông minh  
> **Tính năng mới**: Cross-Encoder Reranking sidecar

---

## 1. Tổng quan kiến trúc

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Browser (Chat Widget)                             │
│                     includes/chatbox.php (JS fetch)                        │
└────────────────────────────┬───────────────────────────────────────────────┘
                             │ POST /api/chatbot
                             ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    AgenticOrchestrator (PHP 8.2)                            │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                          LLM Loop                                    │   │
│  │                                                                      │   │
│  │  1. loadHistory() — 20 messages gần nhất từ DB                      │   │
│  │  2. LLM.chat(messages, tools) → response                            │   │
│  │  3. hasToolCalls()? → loop (max 3 turns)                            │   │
│  │  4. harvestProducts() — auto-extract product cards                   │   │
│  │  5. saveMessages() → DB                                             │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────┐  ┌──────────────────┐  ┌───────────────────┐             │
│  │ LLM Factory  │  │  ToolRegistry    │  │  ChatbotEngine    │             │
│  │ (DeepSeek)   │─▶│  (6 tools +      │  │  (rule-based      │             │
│  │              │  │   reranker)      │  │   fallback)       │             │
│  └─────────────┘  └────────┬─────────┘  └───────────────────┘             │
└────────────────────────────┼───────────────────────────────────────────────┘
                             │ internal HTTP (PHP-to-PHP, port 80)
                             ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Internal API Layer                                 │
│                                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                Products Search (list.php)                            │  │
│  │                                                                      │  │
│  │  SELECT p.*, MATCH(name) AGAINST(...) AS score                       │  │
│  │  FROM products p                                                     │  │
│  │  WHERE MATCH(name) AGAINST('+khoác*' IN BOOLEAN MODE)               │  │
│  │     OR name LIKE '%áo khoác%'                                        │  │
│  │  ORDER BY price ASC                                                  │  │
│  └──────────────────────┬───────────────────────────────────────────────┘  │
│                         │ SQL results (sorted by price)                     │
│                         ▼                                                   │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │              Reranker Sidecar (Python 3.12 + FastAPI)                │  │
│  │                                                                      │  │
│  │  POST /rerank { query, texts[] }                                     │  │
│  │    → cross-encoder: BAAI/bge-reranker-v2-m3                          │  │
│  │    → scores + sorted_indices                                         │  │
│  │    → timeout > 2s → fallback (giữ nguyên SQL order)                  │  │
│  │                                                                      │  │
│  │  Lazy model loading: background thread at startup                    │  │
│  │  Fallback endpoint: /rerank-light (keyword overlap khi model chưa)   │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│  Support endpoints:                                                         │
│  /api/products  /api/size-guide  /api/faq  /api/outfit  /api/categories    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.1 Services (Docker)

| Service | Container | Base Image | Port | RAM |
|---|---|---|---|---|
| `nginx` | `shop_quan_ao_nginx` | nginx:1.27-alpine | ${NGINX_HTTP_PORT:-80}:80 | ~20 MB |
| `app` | `shop_quan_ao_app` | php:8.2-apache | internal 80 | ~100 MB |
| `db` | `shop_quan_ao_db` | mariadb:10.11 | 3308:3306 | ~200 MB |
| `reranker` | `shop_quan_ao_reranker` | python:3.12-slim | 8001:8000 | ~785 MB |
| `phpmyadmin` | `shop_quan_ao_phpmyadmin` | phpmyadmin:5.2 | 8091:80 | profile: tools |

---

## 2. Agentic Loop

### 2.1 `AgenticOrchestrator::respond()`

```
respond(message)
  ├── LLM available?
  │   ├── YES → processWithLLM()
  │   └── NO  → fallbackEngine.respond() (rule-based)
  │
  processWithLLM()
  ├── loadHistory() — 20 messages từ DB
  ├── Build messages: [system_prompt, history..., user_message]
  ├── LLM.chat(messages, tool_definitions)
  │   └── Response types:
  │       ├── text → return trực tiếp
  │       └── tool_call → execute → append result → LLM loop
  │
  execute(tool_name, arguments)
  ├── Check cache (file-based, TTL theo tool)
  ├── HTTP call internal API
  ├── [search_products] → applyRerank(query, products)
  │   ├── count >= 5? → callReranker()
  │   ├── Limit <= 20 items
  │   └── timeout/error → fallback SQL order
  ├── Cache::set(key, result)
  └── return result
```

### 2.2 System Prompt

Chatbot được điều khiển bởi system prompt với 10 rules:

1. **Keyword extraction chính xác**: `"áo khoác dưới 500k"` → `search="áo khoác"`, `max_price=500000`
2. **Luôn gọi tool**: Không dùng lại kết quả từ lịch sử
3. **Hiển thị tất cả**: Không giới hạn số lượng sản phẩm
4. **Chủ động hỏi khi thiếu thông tin**: Phong cách? Form? Giá?
5. **Size → suggest_size**: Nếu thiếu height/weight, hỏi luôn
6. **Phối đồ → get_outfit**
7. **Chính sách → get_faq**
8. **Mỗi câu hỏi là request mới**
9. **Đơn hàng → hướng dẫn vào trang cá nhân**
10. **Luôn có link sản phẩm**: `product.php?id=XX`

---

## 3. Tool Registry

### 3.1 Tool Definitions

```php
$this->tools['search_products'] = [
    'name' => 'search_products',
    'description' => 'Tìm kiếm sản phẩm...',
    'parameters' => [
        'search'     => 'string (required) — từ khóa chính xác',
        'category_id'=> 'integer (1=Áo, 2=Quần, 3=Váy, 4=Phụ kiện)',
        'min_price'  => 'number',
        'max_price'  => 'number',
    ],
];
// + get_product_detail, suggest_size, get_faq, get_outfit, get_categories
```

### 3.2 Execution Flow

```
ToolRegistry::execute(toolName, arguments)
  ├── Tìm method handler: execute{toolName}()
  ├── search_products:
  │   ├── Cache::getSearchResult(queryParams) → hit? → return
  │   ├── GET /api/products?search=...&sort=price_asc&...
  │   ├── applyRerank(query, products):
  │   │   ├── count < 5? → return (skip)
  │   │   ├── slice(0, 20) → productsToRerank
  │   │   ├── POST reranker:8000/rerank { query, texts[] }
  │   │   │   └── Timeout 2s → return null → keep original
  │   │   ├── Merge: reranked first → non-reranked after
  │   │   └── Return reordered products[]
  │   └── Cache::setSearchResult(queryParams, reranked_result)
  │
  └── Other tools: cache → HTTP → cache → return
```

### 3.3 Internal API Calls

PHP-to-PHP calls dùng `http://localhost` (port 80, internal Apache).
Auth endpoints yêu cầu Bearer token, forwarding từ user request.

---

## 4. Cross-Encoder Reranking

### 4.1 Sidecar Service

```
Container: shop_quan_ao_reranker
Image:     docker/reranker/Dockerfile (python:3.12-slim)
Port:      8000 (internal) / 8001 (host)
Model:     BAAI/bge-reranker-v2-m3
Framework: FastAPI + sentence-transformers
PyTorch:   CPU-only (~200 MB, tránh CUDA deps ~1.5 GB)
```

### 4.2 API

**Health:**
```http
GET /health
→ { "status": "ok"|"warming_up", "loaded": bool, "warmup_seconds": int }
```

**Rerank:**
```http
POST /rerank
Content-Type: application/json

{
  "query": "áo khoác",
  "texts": ["Áo thun nam", "Áo khoác da", ...]
}

Response:
{
  "scores": [0.74, 0.84, ...],
  "sorted_indices": [1, 0, ...],
  "elapsed_ms": 448
}
```

### 4.3 Model Loading

```python
# Lazy load — background thread tại startup
def _load_model():
    model = CrossEncoder('BAAI/bge-reranker-v2-m3', device='cpu')
    
# First request: nếu model chưa sẵn sàng → đợi tối đa 120s
# Fallback: keyword overlap sorting
```

### 4.4 Thresholds (`ToolRegistry.php`)

```php
private const RERANK_MIN_RESULTS = 5;    // Skip nếu < 5 kết quả
private const RERANK_MAX_ITEMS = 20;     // Limit items gửi xuống model
private const RERANK_TIMEOUT_MS = 2000;  // Timeout curl (ms)
```

### 4.5 Fallback Behavior

| Scenario | Xử lý |
|---|---|
| Reranker container chưa sẵn sàng | `callReranker()` → null → giữ nguyên SQL order |
| Request timeout (>2s) | curl timeout → null → giữ nguyên |
| HTTP error (5xx) | `error_log` → null → giữ nguyên |
| Model chưa loaded | Fallback về keyword overlap trong sidecar |
| `RERANKER_URL` env không set | Dùng `http://reranker:8000` mặc định |

### 4.6 Latency (Benchmark)

| Items | Latency | Vượt timeout? |
|---|---|---|
| 5 | ~270 ms | ✗ |
| 15 | ~665 ms | ✗ |
| 19 | ~1,225 ms | ✗ |
| 30 | ~1,700 ms | ✓ (fallback) |

Với catalog ~50 sản phẩm, reranker luôn trong timeout 2s.

---

## 5. Hybrid Search Engine

### 5.1 FULLTEXT + LIKE (`api/controllers/products/list.php`)

```
User query: "áo khoác dưới 500k"
                    │
        ┌───────────┴───────────┐
        ▼                       ▼
  FULLTEXT (≥3 chars)      LIKE (all chars)
  ┌──────────────────┐   ┌────────────────────┐
  │ "khoác" (5 chars)│   │ "%áo khoác dưới    │
  │ → MATCH(name)    │   │  500k%"            │
  │   AGAINST(...)   │   │ → name LIKE '%...%'│
  └──────────────────┘   └────────────────────┘
        │                       │
        └───────────┬───────────┘
                    ▼
           WHERE MATCH(name) AGAINST('+khoác*' IN BOOLEAN MODE)
             OR name LIKE '%áo khoác%'
```

### 5.2 Scoring (SQL-based)

```
FULLTEXT score (10x) + Exact match (20x) + Prefix (15x) + Per-word (5x)
→ relevance_score → ORDER BY relevance_score DESC
```

---

## 6. Caching

### 6.1 File-based Cache (`api/cache/Cache.php`)

```
Namespace: /tmp/shop_cache/
Sharding: /tmp/shop_cache/{2-char-hex}/{md5_hash}.cache
Atomic write: write to .{pid}.{uniqid}.tmp → rename
```

### 6.2 TTL per Tool

| Tool | Cache Key Prefix | TTL | Ghi chú |
|---|---|---|---|
| `search_products` | `sp\|` | 5 phút | Đã rerank |
| `product_detail` | `pd\|` | 5 phút | |
| `size_guide` | `sg\|` | 10 phút | |
| `faq` | `faq\|` | 1 giờ | |
| `outfit` | `of\|` | 10 phút | |
| `categories` | `categories` | 24 giờ | |

---

## 7. Fallback Engine (Rule-based)

### 7.1 Intent Classification

```php
const INTENTS = [
    'greeting'       => ['chào', 'hello', ...],
    'product_search' => ['tìm', 'kiếm', 'sản phẩm', ...],
    'size_advice'    => ['size', 'mặc size', 'chọn size', ...],
    'outfit'         => ['phối đồ', 'kết hợp', ...],
    'faq_*'          => ['giao hàng', 'đổi trả', 'thanh toán', ...],
    // + product_detail, order_status, cart, help, bye, unknown
];
```

### 7.2 Search Keywords

```
SEARCH_KEYWORDS ordered by length DESC (longest match first):
'áo sơ mi caro' → 'áo sơ mi'
'áo khoác da'   → 'áo khoác'
'áo khoác nỉ'   → 'áo khoác'
'quần jeans'    → 'quần jeans'
...
```

---

## 8. Chat Widget (Frontend)

### `includes/chatbox.php`

- **Pure JS** (no framework) — `fetch()` to `/api/chatbot`
- **CSS inline** — zero external dependencies
- **Session**: localStorage + sessionStorage for cross-page continuity
- **Features**:
  - Auto-load history (logged-in users)
  - Product cards (image + price + stock + link)
  - Quick reply chips
  - Typing indicator
  - Cross-page continuity

---

## 9. Database Schema

### Chat Tables

```sql
CREATE TABLE chat_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY user_id (user_id)
);

CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    message TEXT NOT NULL,
    metadata LONGTEXT DEFAULT NULL,  -- JSON: { products, orchestrator_type }
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY session_id (session_id)
);

CREATE TABLE tool_executions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    tool_name VARCHAR(100) NOT NULL,
    arguments LONGTEXT DEFAULT NULL,
    result LONGTEXT DEFAULT NULL,  -- JSON
    duration_ms INT DEFAULT NULL,
    success TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY session_id (session_id),
    KEY tool_name (tool_name)
);
```

---

## 10. API Endpoints

### Chat

```http
POST /api/chatbot
→ { message, products[], session_token, session_id }

GET /api/chatbot/history
Authorization: Bearer <token>
→ { messages[], session_token, session_id }
```

### Products

```http
GET /api/products?search=&category=&min_price=&max_price=&sort=&limit=&page=
GET /api/products/{id}
GET /api/products/{id}/sizes
GET /api/products/{id}/reviews
GET /api/products/{id}/reviews          POST
```

### Support

```http
GET /api/size-guide?height=&weight=&category_id=
GET /api/faq?category=&search=
GET /api/outfit?product_id=&search=
GET /api/categories
```

---

## 11. Security

| Risk | Mitigation |
|---|---|
| API key exposure | Load từ env vars, không hardcode |
| SQL injection | Prepared statements everywhere |
| XSS | `textContent` thay vì `innerHTML` |
| Auth | Bearer token + session token |
| Internal API | PHP-to-PHP qua localhost (port 80) |
| Rate limiting | TODO |

---

## 12. Monitoring

- **`tool_executions` table**: tool name, arguments, result, duration, success
- **Reranker latency**: `error_log("Reranker latency: ...ms")`
- **Apache logs**: `docker compose logs -f app`
- **Cache status**: file count under `/tmp/shop_cache/`

---

## 13. Performance Characteristics

| Metric | Giá trị |
|---|---|
| Chat response (LLM, no tool) | ~2-4s |
| Chat response (LLM + search + rerank) | ~4-7s |
| Reranker first request (cold) | ~270s (download model) |
| Reranker subsequent | ~300-1200ms |
| Cache hit response | ~3-5s (LLM only) |
| Fallback (rule-based) | < 500ms |

---

## 14. Future Improvements

- [ ] Streaming response (SSE)
- [ ] Vector embedding cho knowledge base
- [ ] Rate limiting
- [ ] Admin dashboard (chat logs, tool usage stats)
- [ ] Multi-model (Claude, Gemini)
- [ ] Webhook for order via chat
- [ ] A/B testing LLM vs rule-based
- [ ] GPU acceleration cho reranker
