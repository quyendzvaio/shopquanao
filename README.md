# Fashion Shop Chatbot

Chatbot CSKH và tư vấn sản phẩm cơ bản cho website bán quần áo. Backend hiện được tinh gọn theo hướng ReAct Agent + RAG: agent nhận câu hỏi, chọn tool phù hợp, truy xuất dữ liệu sản phẩm/chính sách thật, rồi tổng hợp câu trả lời cuối cho người dùng.

## Phạm Vi Hiện Tại

Chatbot hỗ trợ:

- Hỏi đáp chính sách shop bằng RAG: đổi trả, hoàn tiền, giao hàng, phí ship, thanh toán, bảo hành, bán sỉ, thông tin cửa hàng.
- Tìm sản phẩm cơ bản theo từ khóa, danh mục và khoảng giá.
- Xem chi tiết sản phẩm qua product cards.
- Tư vấn size theo chiều cao/cân nặng.
- Tra trạng thái đơn hàng cho user đã đăng nhập.

Chatbot không hỗ trợ:

- Tư vấn phối đồ/outfit.
- Tự thêm giỏ hàng.
- Tự chuẩn bị checkout hoặc chuyển trang thanh toán.
- Trả lời chính sách nếu không có dữ liệu truy xuất phù hợp.

## Kiến Trúc

```text
Browser
  -> Nginx API Gateway
      -> /api/*, web routes, static assets
      -> PHP app
      -> /api/chatbot
      -> AgenticOrchestrator
          -> LLM provider: DeepSeek-compatible API
          -> ToolRegistry
              -> retrieve_knowledge -> KnowledgeRetriever -> Qdrant optional / local Markdown + DB fallback
              -> search_products -> Product API / DB
              -> get_product_detail -> Product API / DB
              -> suggest_size -> Size guide API / DB
              -> get_order_status -> Orders DB
          -> ChatbotMemory
          -> chat_messages + tool_executions logging
      -> JSON response
```

Docker services:

| Service | Purpose | Default port |
|---|---|---|
| `nginx` | Public API Gateway, reverse proxy, basic chatbot rate limit | `8090:80` |
| `app` | PHP 8.2 Apache web/API app, internal only | `80` internal |
| `db` | MariaDB 10.11 | `3308:3306` |
| `redis` | Optional cache service; app falls back to file cache if `ext-redis` is absent | `6379` |
| `qdrant` | Optional VectorDB for knowledge index, internal only | `6333` internal |
| `reranker` | Lightweight search reranker sidecar for product search | `8001` |
| `phpmyadmin` | Optional DB admin profile | `8091` |

## ReAct Tools

LLM chỉ được gọi các tool này qua `ToolRegistry`:

| Tool | Use case |
|---|---|
| `retrieve_knowledge` | Chính sách, CSKH, FAQ, shop info, size/policy context |
| `search_products` | Tìm sản phẩm theo keyword/category/price |
| `get_product_detail` | Lấy chi tiết một sản phẩm cụ thể |
| `suggest_size` | Tư vấn size theo chiều cao/cân nặng |
| `get_order_status` | Tra đơn hàng cá nhân, yêu cầu đăng nhập nếu thiếu user |
| `get_categories` | Lấy danh mục để hỗ trợ lọc sản phẩm |

Các tool đã loại khỏi chatbot:

- `get_outfit`
- `prepare_checkout`
- `get_faq`

## Knowledge/RAG

Nguồn dữ liệu tri thức:

- `knowledge/policies.md`
- `knowledge/faq.md`
- `knowledge/shop-info.md`
- `knowledge/size-guide.md`
- FAQ/size guide từ database

Luồng hiện tại:

```text
query
  -> query rewriting: chuẩn hóa câu hỏi, mở rộng synonym/domain terms
  -> KnowledgeRetriever.search(query, category, limit=5)
  -> nếu Qdrant configured và có dữ liệu:
       vector search bằng embedding local-hash hiện tại
  -> nếu Qdrant lỗi/chưa ingest/chưa có kết quả:
       local keyword fallback trên Markdown + DB
  -> trả results + source/category/score
  -> agent tổng hợp câu trả lời dựa trên results
```

Production target cho RAG:

```text
query
  -> query rewriting
  -> hybrid search: keyword + vector
  -> cross-encoder rerank
  -> lấy top_k = 5 chunks
  -> agent tổng hợp câu trả lời từ context đã rerank
```

Ghi chú: hiện tại chatbot đã có RAG endpoint, ingest Qdrant, fallback Markdown/DB và eval harness. Phần hybrid search + embedding model thật + rerank cho knowledge chunks là hạng mục còn lại để đạt target production RAG ở trên.

## Quick Start

```bash
cp .env.example .env
# cập nhật LLM_API_KEY, DB_PASS, MARIADB_ROOT_PASSWORD nếu cần

docker compose up -d --build

curl http://localhost:8090/api/products?limit=1

curl -X POST http://localhost:8090/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message":"Shop đổi trả trong bao lâu?"}'
```

Chạy ingest knowledge vào Qdrant:

```bash
docker compose exec app php scripts/ingest_knowledge.php
```

Nếu không ingest hoặc Qdrant chưa sẵn sàng, chatbot vẫn fallback về Markdown + DB.

## Environment

| Variable | Required | Default | Description |
|---|---:|---|---|
| `LLM_PROVIDER` | No | `deepseek` | Provider cho LLM |
| `LLM_API_KEY` | Yes for LLM | empty | API key DeepSeek-compatible |
| `LLM_BASE_URL` | No | `https://api.deepseek.com` | Base URL |
| `LLM_MODEL` | No | `deepseek-chat` | Chat model |
| `LLM_TIMEOUT` | No | `60` | Timeout seconds |
| `DB_HOST` | No | `db` in Docker | MariaDB host |
| `DB_NAME` | No | `shop_db` | Database name |
| `DB_USER` | No | `shop_user` | Database user |
| `DB_PASS` | Yes | `shop_pass` | Database password |
| `REDIS_HOST` | No | `redis` | Optional Redis cache host; current production image can run without `ext-redis` |
| `QDRANT_URL` | No | `http://qdrant:6333` | Qdrant endpoint |
| `EMBEDDING_PROVIDER` | No | `local_hash` | Current embedding provider |
| `EMBEDDING_MODEL` | No | `local-hash-256` | Current embedding model |
| `RERANKER_URL` | No | `http://reranker:8000` | Product reranker URL |

Không commit `.env`. Dùng `.env.example` để mô tả cấu hình mẫu.

## API Gateway

Nginx là entrypoint public của Docker stack:

- Public port: `http://localhost:8090`
- Upstream nội bộ: `app:80`
- `/api/chatbot` có basic rate limit bằng `limit_req`
- Các route web, API và static assets được reverse proxy về PHP app
- Chặn truy cập file nhạy cảm như `.env`, `.git`, `composer.*`, `phpunit.xml`

PHP `app` không expose port trực tiếp ra host trong Docker Compose mặc định.

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

Response:

```json
{
  "message": "Mình tìm thấy 1 sản phẩm phù hợp...\n\nTheo chính sách shop: ...",
  "products": [
    {
      "id": 52,
      "name": "Áo Khoác Bomber Kaki Đen",
      "price": 550000,
      "stock": 12,
      "image": "ak_bomber_03.jpg",
      "image_url": "http://localhost:8090/images/ak_bomber_03.jpg",
      "url": "http://localhost:8090/product.php?id=52"
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

### Products

```http
GET /api/products?search=áo khoác&max_price=600000
GET /api/products/{id}
GET /api/size-guide?height=170&weight=65&category_id=1
```

## Testing

### PHP tests

```bash
composer install

vendor/bin/phpunit --testsuite=Unit --colors=always
vendor/bin/phpunit --testsuite=Integration --colors=always
vendor/bin/phpstan analyse --level=1 api/ config/
```

Nếu host không có Composer/PHP extensions đầy đủ, có thể chạy qua Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 \
  composer install --no-interaction --no-progress --ignore-platform-req=ext-pdo_mysql

docker run --rm -v "$PWD":/app -w /app composer:2 \
  php vendor/bin/phpunit --testsuite=Unit --colors=never
```

Integration test với app container và MariaDB:

```bash
docker exec shop_quan_ao_eval_app sh -lc \
  'cd /var/www/html && APP_ENV=test LLM_PROVIDER= DB_HOST=shop_quan_ao_db DB_NAME=shop_db DB_USER=shop_user DB_PASS=shop_pass php vendor/bin/phpunit --testsuite=Integration --colors=never'
```

### Chatbot eval, RAGAS, LangSmith

Deterministic + latency eval qua Nginx gateway:

```bash
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost:8090 \
  --output reports/chatbot_eval_report.json
```

RAGAS eval với DeepSeek evaluator + HuggingFace local embeddings:

```bash
set -a; . ./.env; set +a
RAGAS_ENABLE=1 \
RAGAS_EMBEDDING_PROVIDER=huggingface \
RAGAS_EMBEDDING_MODEL="sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2" \
LLM_API_KEY="<deepseek-key>" \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost:8090 \
  --cases eval/chatbot_multistep_eval_cases.jsonl \
  --output reports/chatbot_multistep_eval_20260713_ragas_langsmith.json \
  --markdown-output reports/BAO_CAO_CHATBOT_MULTISTEP_RAGAS_LANGSMITH_20260713.md \
  --turn-delay 5.3
```

LangSmith trace:

```bash
LANGSMITH_API_KEY="<langsmith-key>" \
LANGSMITH_PROJECT="fashion-shop-chatbot-multistep-eval" \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost:8090 \
  --cases eval/chatbot_multistep_eval_cases.jsonl \
  --output reports/chatbot_multistep_eval_20260713_ragas_langsmith.json
```

## Kết Quả Kiểm Thử Gần Nhất

Target eval gần nhất: `http://localhost:8090` qua Nginx API Gateway. Vì `/api/chatbot` có `limit_req`, multi-turn eval dùng `--turn-delay 5.3`.

| Check | Result |
|---|---:|
| Full PHPUnit, PHP 8.2 app container | `70 tests, 201 assertions, PASS` |
| Integration tests, PHP 8.2 + DB fallback | `10 tests, 46 assertions, PASS` |
| PHPStan level 1 | `PASS` |
| PHP syntax lint | `PASS` |
| Python syntax compile | `PASS` |
| Secret scan regex | `PASS` |
| PHPCS PSR-12 | `FAIL legacy style debt, non-blocking in CI` |

Multi-step chatbot eval + RAGAS + LangSmith:

| Metric | Value |
|---|---:|
| Scenarios | `5` |
| Turns | `25` |
| Passed | `23` |
| Failed | `2` |
| Latency min | `829 ms` |
| Latency avg | `1190.16 ms` |
| Latency p50 | `1125 ms` |
| Latency p95 | `1779 ms` |
| Latency max | `1872 ms` |
| Faithfulness | `0.7284` |
| Answer relevancy | `0.4792` |
| Context precision | `0.7121` |
| Context recall | `0.5455` |
| Embedding evaluator | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, local HuggingFace |

LangSmith:

| Metric | Value |
|---|---:|
| Project | `fashion-shop-chatbot-multistep-eval` |
| Trace upload | success |
| Successful traces seen | `call_chatbot`, `call_knowledge` |
| API key storage | env-only, not committed |

Known failures in latest multi-step run:

- `policy_refund_time`: retrieval/answer missed the expected `1-3 ngày` processing time.
- `outfit_removed_2`: guardrail was bypassed when the user asked for a “set đi chơi”; chatbot searched products instead of saying outfit/set styling is out of scope.

Report files:

- `reports/chatbot_multistep_eval_20260713_ragas_langsmith.json`
- `reports/BAO_CAO_CHATBOT_MULTISTEP_RAGAS_LANGSMITH_20260713.md`

Eval coverage:

- Chính sách đổi trả cơ bản.
- Hàng sale trên 50%.
- Lỗi đường may + đổi size + phí ship + thời gian xử lý.
- Phí ship đơn 300k và miễn phí từ 500k.
- Tìm áo khoác dưới 600k.
- Mixed intent: áo bomber còn hàng và đổi size.
- Tư vấn size từ chiều cao/cân nặng.
- Guardrail: không checkout thay user.
- Guardrail: không tư vấn phối đồ.

## CI/CD

Workflow: `.github/workflows/ci.yml`

Jobs:

| Job | Purpose | Blocking |
|---|---|---:|
| `code-quality` | Composer validate, PHP lint, PHPCS non-blocking, Python compile | partial |
| `unit-tests` | PHPUnit Unit | yes |
| `integration-tests` | PHPUnit Integration với MariaDB service | yes |
| `security` | Secret scan, hardcoded password scan, Trivy filesystem | secret/password yes, Trivy non-blocking |
| `docker` | Build app + reranker images, Trivy image scan | build yes, Trivy non-blocking |
| `deploy` | SSH deploy main/master | gated by tests/security/docker |

Nhận xét hiện tại:

- Unit/integration/PHPStan đã ổn sau khi fix cache TTL và PHPUnit cache directory.
- `composer.lock` nên được commit để CI dùng dependency reproducible.
- PHPCS đang non-blocking vì code legacy còn nhiều lỗi PSR-12. Muốn production gate nghiêm hơn thì cần format/refactor theo từng module trước khi bỏ `|| echo`.
- RAGAS/LangSmith hiện là manual evaluation evidence đã chạy một lần và lưu kết quả trong report/README. Không chạy bắt buộc trong CI public vì cần secret evaluator và phát sinh chi phí; nếu cần regression eval, thêm workflow `workflow_dispatch` hoặc scheduled workflow riêng.

## Ignore Policy

Đã ignore:

- `.env`, `.env.local`, `.env.*.local`
- `vendor/`
- `.phpunit.cache/`
- `reports/`, `test-evidence/`, `postman/`
- Python cache/venv: `__pycache__/`, `.venv/`, `venv/`, `.pytest_cache/`, `.mypy_cache/`, `.ruff_cache/`, `.coverage`, `htmlcov/`
- local agent/spec folders: `.claude/`, `.codegraph/`, `.specify/`
- backups/dumps/archives

Không ignore:

- `composer.lock`
- `eval/`
- `scripts/`
- `tests/eval/`

Lý do: source eval/test/script cần version control; report output và runtime cache không nên commit.

## Project Structure

```text
api/
  cache/                      File cache with optional Redis backend
  controllers/chatbot/         Agent, tools, memory, LLM provider, fallback engine
  controllers/knowledge/       Knowledge search endpoint
  controllers/products/        Product APIs
config/                        DB bootstrap
docker/                        Apache/PHP/reranker configs
eval/                          Chatbot eval harness
knowledge/                     Markdown knowledge base
scripts/                       Knowledge ingest and eval helpers
sql/                           Schema and migrations
tests/                         PHPUnit unit/integration tests
```

## Production Gaps Còn Lại

- Nginx API Gateway đã có basic `limit_req`; chưa có distributed rate limit/Redis-backed rate limit cho nhiều instance.
- Retrieval chưa phải hybrid search + cross-encoder rerank đúng yêu cầu production RAG.
- Embedding hiện tại vẫn là `local_hash`; cần thay bằng Vietnamese embedding model thật và reindex Qdrant.
- PHPCS chưa thể bật blocking do style debt legacy.
- RAGAS `answer_relevancy` đã đo bằng HuggingFace local embedding; điểm còn thấp nên cần cải thiện quality answer/context sau khi nâng retrieval.

## License

Proprietary/internal project.
