# Fashion Shop ReAct RAG Chatbot

Backend chatbot cho website bán quần áo, tập trung vào hai nhóm nghiệp vụ:

- CSKH/chính sách shop bằng RAG: đổi trả, hoàn tiền, giao hàng, phí ship, thanh toán, bảo hành, bán sỉ, thông tin cửa hàng.
- Tư vấn sản phẩm cơ bản: tìm sản phẩm, xem chi tiết, tư vấn size, kiểm tra trạng thái đơn hàng khi người dùng đã đăng nhập.

Chatbot được thiết kế theo hướng ReAct Agent: LLM không tự trả lời mọi thứ, mà chọn tool phù hợp, lấy dữ liệu thật từ DB/knowledge base, sau đó tổng hợp câu trả lời cuối cho người dùng. Chatbot không tự thêm giỏ hàng, không checkout hộ và không tư vấn phối đồ.

## Công Nghệ Sử Dụng

| Nhóm | Công nghệ |
|---|---|
| Backend | PHP 8.2, Apache |
| API Gateway | Nginx reverse proxy, basic rate limit cho `/api/chatbot` |
| Database | MariaDB 10.11 |
| Cache | Redis optional, fallback file cache nếu thiếu Redis extension |
| LLM | DeepSeek-compatible OpenAI API format |
| Agent | `AgenticOrchestrator`, `ToolRegistry`, function/tool calling |
| RAG | Markdown knowledge base, DB FAQ/size guide, Qdrant optional, local fallback |
| Query Processing | Query rewriting cho knowledge retrieval |
| Product Rerank | Python FastAPI sidecar, TF-IDF character n-gram reranker |
| Evaluation | PHPUnit, PHPStan, RAGAS, LangSmith, HuggingFace local embeddings |
| Container | Docker Compose |

## Kiến Trúc Runtime

```text
Browser
  -> Nginx API Gateway :8090
      -> PHP app :80 internal
          -> /api/chatbot
              -> AgenticOrchestrator
                  -> DeepSeek LLM
                  -> ToolRegistry
                      -> retrieve_knowledge
                      -> search_products
                      -> get_product_detail
                      -> suggest_size
                      -> get_order_status
                      -> get_categories
                  -> AgentEvaluator
                  -> ChatbotMemory
                  -> chat_messages + tool_executions
          -> JSON response
```

Docker services:

| Service | Vai trò | Port |
|---|---|---:|
| `nginx` | API Gateway public, reverse proxy, rate limit | `8090:80` |
| `app` | PHP 8.2 Apache app, internal only | `80` internal |
| `db` | MariaDB | `3308:3306` |
| `redis` | Cache optional | `6379` |
| `qdrant` | VectorDB optional cho knowledge index | internal `6333` |
| `reranker` | Product search reranker sidecar | `8001:8000` |
| `phpmyadmin` | DB admin, profile `tools` | `8091:80` |

## Luồng Kỹ Thuật Bên Trong Chatbot

### 1. Luồng ReAct Agent

```text
User message
  -> AgenticOrchestrator nạp history + memory
  -> LLM phân loại ý định và gọi tool nếu cần
  -> ToolRegistry execute tool
  -> kết quả tool được đưa lại vào LLM
  -> LLM sinh draft answer
  -> AgentEvaluator kiểm tra hard constraints
  -> trả response cuối: message + products + knowledge_sources
```

Các tool chatbot hiện hỗ trợ:

| Tool | Nguồn dữ liệu | Mục đích |
|---|---|---|
| `retrieve_knowledge` | Markdown, FAQ/size DB, Qdrant optional | Hỏi đáp chính sách/CSKH |
| `search_products` | Product API/DB | Tìm sản phẩm theo keyword, category, price |
| `get_product_detail` | Product API/DB | Xem chi tiết một sản phẩm |
| `suggest_size` | Size guide API/DB | Tư vấn size theo chiều cao/cân nặng |
| `get_order_status` | Orders DB | Tra trạng thái đơn hàng của user đăng nhập |
| `get_categories` | Categories DB | Hỗ trợ lọc danh mục |

Các tool đã loại khỏi chatbot: `get_outfit`, `prepare_checkout`, `get_faq`.

### 2. Luồng RAG Chính Sách

```text
Policy/CSKH question
  -> query rewriting
  -> KnowledgeRetriever.search(query, category, limit=5)
  -> Qdrant vector search nếu configured và có index
  -> fallback local keyword search trên Markdown + DB nếu Qdrant lỗi/chưa ingest
  -> trả top contexts + source/category/score
  -> LLM chỉ tổng hợp dựa trên contexts
```

Nguồn tri thức:

- `knowledge/policies.md`
- `knowledge/faq.md`
- `knowledge/shop-info.md`
- `knowledge/size-guide.md`
- FAQ và bảng size trong database

Mục tiêu production tiếp theo cho RAG:

```text
query rewriting
  -> hybrid search: keyword + vector
  -> cross-encoder rerank
  -> top_k = 5 chunks
  -> grounded answer
```

### 3. Luồng Product Basic

```text
Product question
  -> search_products / get_product_detail
  -> product API hoặc DB
  -> product cards được trả riêng trong response
  -> message ngắn hướng dẫn user bấm thẻ sản phẩm
```

Chatbot chỉ hỗ trợ tư vấn/tìm kiếm. Người dùng tự thao tác thêm giỏ hàng và thanh toán trên UI.

### 4. Luồng Evaluator/Self-Reflection

Sau khi LLM sinh draft answer, evaluator kiểm tra trước khi response ra frontend.

| Task | Hard constraints |
|---|---|
| Product search | đúng category, đúng khoảng giá, product id tồn tại, không bịa thuộc tính |
| Product detail | đúng product id, đúng giá/stock/size, đủ schema card |
| Size advice | đủ chiều cao/cân nặng, dùng bảng size, không khẳng định 100% |
| Order status | phải xác thực user, không lộ dữ liệu nhạy cảm, không đoán trạng thái |

Nếu không đạt, evaluator chọn một trong các hướng: trả lời, sửa answer, retry tool khi lỗi tạm thời, hỏi thêm user, fallback an toàn hoặc từ chối do chưa xác thực.

## API Chính

### Chatbot

```http
POST /api/chatbot
Content-Type: application/json
Authorization: Bearer <api_token>  # optional

{
  "message": "Áo bomber nếu không vừa size thì đổi được không?",
  "session_token": "optional"
}
```

Response mẫu:

```json
{
  "message": "Mình tìm thấy sản phẩm phù hợp. Theo chính sách shop, bạn có thể đổi size nếu đáp ứng điều kiện đổi trả.",
  "products": [
    {
      "id": 52,
      "name": "Áo Khoác Bomber Kaki Đen",
      "price": 550000,
      "stock": 12,
      "stock_status": "in_stock",
      "available_sizes": ["S", "M", "L"],
      "available_colors": [],
      "image_url": "http://localhost/images/ak_bomber_03.jpg",
      "url": "http://localhost/product.php?id=52"
    }
  ],
  "knowledge_sources": [
    {
      "source": "knowledge/policies.md",
      "title": "Chính sách đổi trả",
      "category": "return",
      "score": 10
    }
  ],
  "session_token": "...",
  "session_id": 1
}
```

### Knowledge Search

```http
GET /api/knowledge/search?q=đổi%20trả&category=return&limit=5
```

### Product APIs

```http
GET /api/products?search=áo khoác&max_price=600000
GET /api/products/{id}
GET /api/size-guide?height=170&weight=65&category_id=1
```

## Metrics Đã Đo

Môi trường đo gần nhất: `http://localhost:8090` qua Nginx API Gateway.

### CI-like Checks Local

| Check | Kết quả |
|---|---:|
| PHPUnit full | `70 tests, 201 assertions, PASS` |
| PHPStan level 1 | `PASS` |
| Python syntax compile | `PASS` |
| Docker Compose config | `PASS` |
| Docker build app image | `PASS` |
| Docker build reranker image | `PASS` |
| Secret scan regex | `PASS` |
| PHPCS PSR-12 | non-blocking do legacy style debt |

### Multi-Step Chatbot Eval

Bộ eval: `5 scenarios x 5 turns = 25 turns`.

| Metric | Giá trị |
|---|---:|
| Scenarios | `5` |
| Turns | `25` |
| Deterministic passed | `23` |
| Deterministic failed | `2` |
| Latency min | `829 ms` |
| Latency avg | `1190.16 ms` |
| Latency p50 | `1125 ms` |
| Latency p95 | `1779 ms` |
| Latency max | `1872 ms` |

Hai failure hiện tại:

- `policy_refund_time`: retrieval/answer chưa trả đúng mốc xử lý `1-3 ngày`.
- `outfit_removed_2`: guardrail phối đồ bị bypass với câu hỏi “set đi chơi”.

### RAGAS + LangSmith

RAGAS chạy offline/manual, không nằm trong Docker runtime và không chạy bắt buộc trong CI public.

| Metric | Giá trị |
|---|---:|
| Faithfulness | `0.7284` |
| Answer relevancy | `0.4792` |
| Context precision | `0.7121` |
| Context recall | `0.5455` |
| Embedding evaluator | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` local HuggingFace |
| LangSmith project | `fashion-shop-chatbot-multistep-eval` |

Ghi chú:

- HuggingFace embedding model chạy local, không cần OpenAI API key.
- LangSmith và DeepSeek keys chỉ truyền bằng biến môi trường khi chạy eval, không ghi vào repo.
- Output report nằm trong `reports/` và bị ignore.

## Hướng Dẫn Chạy Dự Án

### 1. Chuẩn Bị Môi Trường

```bash
cp .env.example .env
```

Cập nhật các biến quan trọng trong `.env`:

```env
LLM_API_KEY=your-deepseek-key
DB_PASS=shop_pass
MARIADB_ROOT_PASSWORD=root_pass
```

Không commit `.env`.

### 2. Chạy Bằng Docker Compose

```bash
docker compose up -d --build
```

Kiểm tra service:

```bash
docker compose ps
curl http://localhost:8090/api/products?limit=1
```

Gọi chatbot:

```bash
curl -X POST http://localhost:8090/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message":"Shop đổi trả trong bao lâu?"}'
```

### 3. Ingest Knowledge Vào Qdrant

```bash
docker compose exec app php scripts/ingest_knowledge.php
```

Nếu chưa ingest hoặc Qdrant lỗi, chatbot vẫn fallback về Markdown + DB.

### 4. Chạy Test

Nếu host có PHP/Composer:

```bash
composer install
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Integration
vendor/bin/phpstan analyse --level=1 api/ config/
```

Nếu chạy qua Docker image app:

```bash
docker run --rm -v "$PWD":/work -w /work shop_quan_ao-app \
  php vendor/bin/phpunit --colors=never

docker run --rm -v "$PWD":/work -w /work shop_quan_ao-app \
  php -d memory_limit=512M vendor/bin/phpstan analyse --level=1 api/ config/ --no-progress --memory-limit=512M
```

### 5. Chạy Eval Offline RAGAS/LangSmith

Cài dependency eval ngoài Docker runtime:

```bash
python3 -m pip install --user -r eval/requirements-eval.txt
```

Chạy eval multi-step:

```bash
set -a; . ./.env; set +a

RAGAS_ENABLE=1 \
RAGAS_EMBEDDING_PROVIDER=huggingface \
RAGAS_EMBEDDING_MODEL="sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2" \
LANGSMITH_API_KEY="<langsmith-key>" \
LANGSMITH_PROJECT="fashion-shop-chatbot-multistep-eval" \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost:8090 \
  --cases eval/chatbot_multistep_eval_cases.jsonl \
  --output reports/chatbot_multistep_eval.json \
  --markdown-output reports/chatbot_multistep_eval.md \
  --turn-delay 5.3 \
  --timeout 120
```

`--turn-delay 5.3` giúp tránh Nginx rate limit khi chạy nhiều turn liên tiếp.

## Biến Môi Trường Chính

| Biến | Bắt buộc | Mặc định | Ý nghĩa |
|---|---:|---|---|
| `LLM_PROVIDER` | No | `deepseek` | Provider LLM |
| `LLM_API_KEY` | Yes nếu dùng LLM | empty | API key DeepSeek-compatible |
| `LLM_BASE_URL` | No | `https://api.deepseek.com` | LLM base URL |
| `LLM_MODEL` | No | `deepseek-chat` | LLM model |
| `DB_HOST` | No | `db` | DB host trong Docker |
| `DB_NAME` | No | `shop_db` | DB name |
| `DB_USER` | No | `shop_user` | DB user |
| `DB_PASS` | Yes | `shop_pass` | DB password |
| `REDIS_HOST` | No | `redis` | Redis cache host |
| `QDRANT_URL` | No | `http://qdrant:6333` | Qdrant endpoint |
| `EMBEDDING_PROVIDER` | No | `local_hash` | Embedding provider runtime hiện tại |
| `EMBEDDING_MODEL` | No | `local-hash-256` | Embedding model runtime hiện tại |
| `RERANKER_URL` | No | `http://reranker:8000` | Reranker sidecar |

## CI/CD

Workflow: `.github/workflows/ci.yml`

| Job | Nội dung |
|---|---|
| `code-quality` | Composer validate, PHP lint, PHPStan, Python compile, PHPCS non-blocking |
| `unit-tests` | PHPUnit Unit |
| `integration-tests` | PHPUnit Integration với MariaDB service |
| `security` | Secret scan, hardcoded password scan, Trivy filesystem |
| `docker` | Build app image và reranker image |
| `deploy` | SSH deploy khi push `main/master`, cần GitHub secrets |

Trạng thái hiện tại: local CI-like checks pass; remote GitHub Actions cần chạy sau khi commit/push.

## Ignore Policy

Được commit:

- Source `api/`, `config/`, `docker/`, `knowledge/`, `scripts/`, `sql/`, `tests/`, `eval/`
- `.env.example`
- `composer.json` và `composer.lock`

Không commit:

- `.env`, `.env.*`
- `vendor/`
- `reports/`, `test-evidence/`, `postman/`
- HuggingFace/model/cache local: `.cache/`, `.huggingface/`, `hf_cache/`, `models/`
- DB/local dumps: `*.db`, `*.sqlite`, `*.sql.gz`, archives

## Production Gaps

- Runtime RAG vẫn cần nâng lên hybrid search + vector + cross-encoder rerank chính thức.
- Runtime embedding hiện là `local_hash`; cần thay bằng Vietnamese/multilingual embedding model thật và reindex Qdrant.
- `answer_relevancy` còn thấp, cần cải thiện chất lượng retrieval và grounded answer.
- Guardrail “không tư vấn phối đồ” cần nhận diện tốt hơn các câu hỏi kiểu “set đi chơi”.
- Rate limit hiện dùng Nginx local instance; multi-instance production nên dùng distributed rate limit.

## License

Proprietary/internal project.
