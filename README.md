# Fashion Shop Agentic RAG Chatbot

Chatbot AI cho website bán quần áo, hỗ trợ tra cứu chính sách CSKH, tìm kiếm và xem chi tiết sản phẩm, tư vấn size và kiểm tra trạng thái đơn hàng.
Hệ thống kết hợp structured ReAct loop, deterministic rules và Knowledge RAG; các câu hỏi phổ biến được xử lý bằng pipeline nhanh, LLM chỉ tham gia khi cần hoàn thiện ngữ nghĩa hoặc fallback.

## Phạm Vi Chức Năng

| Nhóm | Chức năng |
|---|---|
| Sản phẩm | Tìm theo từ khóa, danh mục, khoảng giá, tồn kho và size |
| Chi tiết | Trả đúng sản phẩm theo ID, giá và tồn kho mới nhất, product card có ảnh |
| Size | Gợi ý size từ chiều cao, cân nặng và bảng `size_guides` |
| Chính sách | Đổi trả, hoàn tiền, giao hàng, phí vận chuyển, bảo hành và thanh toán |
| Đơn hàng | Yêu cầu đăng nhập, kiểm tra ownership và trả trạng thái đơn của user |
| Memory | Guest dùng session summary + slot memory; user đăng nhập có thêm long-term memory |

Chatbot không tư vấn phối đồ, không tự thêm giỏ hàng và không thực hiện checkout. Khi người dùng muốn mua, chatbot hướng dẫn mở product card hoặc trang chi tiết sản phẩm.

## Kiến Trúc

Dự án có hai service logic chính:

1. **ReAct Agent Service**: `AgenticOrchestrator` trong PHP app chịu trách nhiệm hiểu yêu cầu, lập kế hoạch, gọi tool, đánh giá evidence và sinh câu trả lời.
2. **Knowledge RAG Service**: `KnowledgeRetriever` trong PHP app điều phối query rewriting, lexical/vector retrieval, fusion và gọi `rag-ml` để embedding/rerank; dữ liệu vector nằm trong Qdrant. RAG cũng được expose qua `GET /api/knowledge/search`.

Hai service logic này được triển khai bằng nhiều container, không phải chỉ hai container độc lập.

```text
Browser
  -> Nginx API Gateway :80
      -> POST /api/chatbot
          -> PHP 8.2 / AgenticOrchestrator
              -> Memory
              -> DeterministicIntentParser
              -> ConflictDetector / ConflictResolver
              -> LLMSemanticCompletion khi còn phần mơ hồ
              -> ReasoningLoop (tối đa 3 vòng)
                  -> ThoughtStateBuilder
                  -> ToolPlanner + PlanValidator
                  -> ParallelToolExecutor
                      -> Product / Size / Order tools -> MariaDB
                      -> retrieve_knowledge -> KnowledgeRetriever
                          -> lexical search -> Markdown + MariaDB
                          -> vector search -> rag-ml /embed -> Qdrant
                          -> RRF fusion
                          -> rag-ml /rerank
                          -> top 5 chunks
                  -> ObservationEvaluator
                  -> LightweightEvidenceScorer
                  -> DecisionRouter + NoProgressDetector
              -> ResponseGenerator
              -> OnlineValidator
          -> chat_messages + tool_executions
      -> JSON response
```

### Container Runtime

| Container | Vai trò |
|---|---|
| `nginx` | API Gateway, reverse proxy và rate limit chatbot |
| `app` | PHP/Apache shop API, Agent Service và RAG orchestration |
| `db` | MariaDB cho dữ liệu nghiệp vụ, memory và logs |
| `redis` | Cache; hệ thống fallback sang file cache khi Redis không dùng được |
| `qdrant` | Vector store cho collection `shop_knowledge_v2` |
| `rag-ml` | FastAPI sidecar cho semantic embedding và knowledge reranking |
| `reranker` | FastAPI sidecar TF-IDF dành riêng cho product search |

## Công Nghệ

| Thành phần | Công nghệ/Kỹ thuật |
|---|---|
| Gateway | Nginx 1.27, `limit_req` 12 request/phút, burst 6 |
| Backend | PHP 8.2, Apache, PDO |
| Database | MariaDB 10.11 |
| Cache | Redis 7, atomic file fallback |
| Vector store | Qdrant 1.12.4 |
| Knowledge embedding | `bkai-foundation-models/vietnamese-bi-encoder`, vector 768 chiều |
| Knowledge reranker | `itdainb/PhoRanker` cross-encoder |
| Product reranker | scikit-learn TF-IDF char n-gram |
| ML sidecar | FastAPI, SentenceTransformers, PyTorch CPU |
| LLM | DeepSeek API, OpenAI-compatible function calling format |
| Offline evaluation | RAGAS 0.1.21, LangSmith, HuggingFace local embeddings |
| Deployment | Docker Compose, GitHub Actions, SSH deploy |

## Luồng ReAct Agent

### 1. Nhận Query Và Memory

`POST /api/chatbot` nhận `message` và `session_token`; Bearer token được dùng để xác định user đăng nhập. Backend load session summary và slot memory trước khi phân tích query. Long-term memory chỉ được nạp khi request có user hợp lệ.

### 2. Intent Và Constraint Extraction

`DeterministicIntentParser` trích xuất các field ổn định như `product_id`, product type, khoảng giá, size, chiều cao/cân nặng, tồn kho, order ID và policy intent. Product slot từ hội thoại trước chỉ được dùng khi câu hiện tại thực sự tham chiếu sản phẩm đó, tránh biến câu policy thành product search.

`ConflictDetector` và `ConflictResolver` bắt các điều kiện mâu thuẫn. `LLMSemanticCompletion` chỉ xử lý unresolved spans như style hoặc occasion và không được ghi đè field deterministic đã khóa.

### 3. Thought, Action, Observation, Repeat

```text
ThoughtStateBuilder
  -> ToolPlanner
  -> deterministic PlanValidator
  -> Action: execute tools
  -> ObservationEvaluator
  -> LightweightEvidenceScorer
  -> DecisionRouter
  -> return hoặc repeat
```

Thought được lưu dưới dạng structured state gồm goal, known facts và missing evidence; hệ thống không lưu hoặc expose chain-of-thought tự do.

Giới hạn loop:

| Budget | Giá trị |
|---|---:|
| Reasoning loops | `3` |
| Tổng tool calls | `4` |
| Query rewrite | `1` |
| Tool retry | `1` |

`NoProgressDetector` tạo fingerprint từ tool, normalized arguments và result signature. Action/result lặp lại sẽ dừng loop để tránh timeout.

### 4. Evidence Scoring Và Decision

`LightweightEvidenceScorer` chạy bằng PHP rules, không gọi RAGAS hoặc LangSmith trong production request.

```text
score =
  0.40 * required_fact_coverage
+ 0.25 * source_reliability
+ 0.20 * retrieval_quality
+ 0.15 * contradiction_score
```

Evidence chỉ pass khi score và required-fact coverage đều đạt ít nhất `0.75`, đồng thời không có hard failure. `DecisionRouter` chọn một trong các action: `return`, `call_next_tool`, `rewrite_query`, `retry_tool`, `ask_user`, `fallback` hoặc `deny`.

### 5. Response Và Validation

`EvidenceNormalizer` chuyển tool output thành facts có source, entity ID, value, freshness và confidence. `ResponseGenerator` tạo câu trả lời từ evidence; `OnlineValidator` kiểm tra product ID, price, stock, size, auth và order ownership trước khi trả response.

## Tool Registry

| Tool | Nhiệm vụ | Nguồn dữ liệu |
|---|---|---|
| `retrieve_knowledge` | Truy xuất chính sách và hướng dẫn shop | Knowledge RAG |
| `search_products` | Tìm sản phẩm theo filter | MariaDB + TF-IDF product reranker |
| `get_product_detail` | Lấy đúng một sản phẩm theo ID | MariaDB |
| `suggest_size` | Tư vấn size | MariaDB `size_guides` |
| `get_order_status` | Tra đơn của user đã đăng nhập | MariaDB `orders` |
| `get_categories` | Lấy danh mục hỗ trợ product search | MariaDB `categories` |

`get_outfit`, `prepare_checkout` và `get_faq` không nằm trong tool definitions của chatbot.

## Knowledge RAG

### Nguồn Dữ Liệu

| Nguồn | Nội dung |
|---|---|
| `knowledge/policies.md` | Đổi trả, hoàn tiền, giao hàng, bảo hành và thanh toán |
| `knowledge/faq.md` | FAQ của shop |
| `knowledge/shop-info.md` | Địa chỉ, liên hệ và thông tin cửa hàng |
| `knowledge/size-guide.md` | Hướng dẫn chọn size |
| MariaDB `faqs`, `size_guides` | FAQ và bảng quy đổi size |

Markdown được parse theo heading; mỗi section trở thành một document với `title`, `content`, `category`, `source` và `updated_at`. Script ingest embedding documents rồi upsert vào Qdrant collection `shop_knowledge_v2`.

### Retrieval Pipeline

```text
query
  -> deterministic query rewriting
  -> retrieval cache lookup
  -> lexical token-overlap search top 12 trên Markdown + DB
  -> embedding cache hoặc rag-ml /embed
  -> Qdrant cosine vector search top 12
  -> deduplicate theo document key
  -> Reciprocal Rank Fusion, k=60
  -> rerank cache hoặc PhoRanker cross-encoder
  -> top 5 knowledge chunks
```

Lexical retrieval hiện dùng weighted token overlap, không phải BM25. Product TF-IDF reranker là sidecar riêng và không được dùng để rerank policy chunks.

Fallback modes:

| Tình huống | Kết quả |
|---|---|
| Qdrant hoặc embedding lỗi | `lexical_fallback` |
| Hybrid có kết quả nhưng reranker lỗi | `hybrid_no_rerank` |
| Đủ vector, lexical và reranker | `hybrid_reranked` |
| Không có context | Không tự bịa policy; trả fallback/clarification |

## Data, Memory Và Cache

MariaDB lưu products, categories, product sizes, size guides, users, orders, chat sessions, chat messages, tool executions, FAQ và long-term memory.

| Cache | TTL mặc định | Chính sách |
|---|---:|---|
| Embedding | 7 ngày | Theo model, preprocess version và text hash |
| Knowledge retrieval | 1 giờ | Theo query/category/top-k/knowledge version |
| Knowledge rerank | 1 giờ | Theo query và candidate IDs |
| Product search | 60 giây | Chỉ cache product IDs; price/stock được đọc lại từ DB |
| Product detail | 15 phút | Chỉ cache metadata tĩnh; price/stock được đọc lại từ DB |
| Size guide | 10 phút | Theo measurements/category |
| Order status | Không cache | Luôn query theo authenticated `user_id` |

## API

### Chatbot

```http
POST /api/chatbot
Content-Type: application/json

{
  "message": "Có áo khoác dưới 600k còn hàng không?",
  "session_token": "optional"
}
```

Response giữ tương thích với frontend cũ và bổ sung metadata của pipeline:

```json
{
  "message": "...",
  "products": [],
  "knowledge_sources": [],
  "session_token": "...",
  "session_id": 1,
  "answer": "...",
  "cards": [],
  "response_type": "final_answer",
  "primary_intent": "product_search",
  "secondary_intents": [],
  "requested_fields": ["price", "stock"],
  "missing_slots": [],
  "trace_id": "...",
  "latency": {}
}
```

Product cards dùng relative URL `/product.php?id={id}` và `/images/{image}` để hoạt động đúng sau Nginx/domain.

### Knowledge Search

```http
GET /api/knowledge/search?q=shop%20đổi%20trả%20trong%20bao%20lâu&category=return&limit=5
```

## Benchmark Gần Nhất

Điều kiện đo ngày `2026-07-21`:

- 5 scenario, 25 positive turns bằng tiếng Việt; không gồm guardrail/refusal cases.
- Target `http://localhost` qua Nginx port 80 và Docker Compose runtime đã warm.
- Qdrant collection `shop_knowledge_v2`; knowledge embedding và RAGAS embedding cùng dùng `bkai-foundation-models/vietnamese-bi-encoder`.
- `answer_relevancy strictness=4`, DeepSeek evaluator và prompt sinh reverse-question bằng tiếng Việt.
- HTTP latency gồm Nginx và local container network, không gồm browser hoặc Internet client latency.
- Cấu hình phần cứng host không được ghi lại trong benchmark, vì vậy không suy diễn throughput theo loại máy.

### Functional Và Latency

| Metric | Giá trị |
|---|---:|
| Deterministic pass | `25/25` |
| Deterministic fail | `0/25` |
| Min latency | `20 ms` |
| Average latency | `369.08 ms` |
| P50 latency | `231 ms` |
| P95 latency | `1072 ms` |
| Max latency | `1124 ms` |

### RAGAS

| Metric | Giá trị | Phạm vi |
|---|---:|---|
| Answer relevancy | `0.5504` | 25 turns |
| Faithfulness | `0.8676` | 20 turns có evidence context |
| Context precision | `0.9125` | 20 turns có evidence context |
| Context recall | `0.8500` | 20 turns có evidence context |

RAGAS chỉ chạy offline/manual, không nằm trong production request và không phải CI gate.

### LangSmith

Project: `fashion-shop-vietnamese-positive25-20260721-v3`

| Root run | Count | Avg | Min | Max |
|---|---:|---:|---:|---:|
| `call_chatbot` | `25` | `370.62 ms` | `21.27 ms` | `1125.13 ms` |
| `call_knowledge` | `15` | `222.87 ms` | `4.46 ms` | `2530.15 ms` |
| `ragas evaluation` | `2` | `14363.05 ms` | `7077.66 ms` | `21648.44 ms` |

LangSmith ghi nhận tổng cộng `42` root traces và `0` trace lỗi. LangSmith dùng để tracing; bốn quality metrics phía trên do RAGAS tính.

## Cài Đặt Và Chạy

### 1. Cấu Hình

```bash
cp .env.example .env
```

Cập nhật tối thiểu:

```env
DB_PASS=your-database-password
MARIADB_ROOT_PASSWORD=your-root-password
LLM_API_KEY=your-deepseek-api-key
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-chat
NGINX_HTTP_PORT=80
```

### 2. Start Stack

```bash
docker compose up -d --build
docker compose ps
```

Nếu port 80 đang được dùng:

```bash
NGINX_HTTP_PORT=8090 docker compose up -d --build
```

### 3. Index Knowledge

Chạy sau lần deploy đầu tiên và mỗi khi đổi tài liệu, embedding model hoặc vector dimension:

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

### 4. Smoke Test

```bash
curl 'http://localhost/api/products?limit=1'

curl -X POST 'http://localhost/api/chatbot' \
  -H 'Content-Type: application/json' \
  -d '{"message":"áo thun giá rẻ"}'

curl --get 'http://localhost/api/knowledge/search' \
  --data-urlencode 'q=shop đổi trả trong bao lâu' \
  --data 'category=return' \
  --data 'limit=5'
```

## Test Và CI

Kết quả local gần nhất:

| Check | Kết quả |
|---|---|
| PHPUnit | `98 tests, 324 assertions` pass |
| PHPStan level 1 | No errors |
| Python syntax | Pass |
| Docker Compose config | Pass |
| Docker services | Healthy |

Lệnh kiểm tra:

```bash
php vendor/bin/phpunit
php vendor/bin/phpstan analyse --level=1 api/ config/ --no-progress
python3 -m py_compile eval/run_chatbot_eval.py docker/reranker/app.py docker/rag-ml/app.py
docker compose config --quiet
```

GitHub Actions gồm code quality, unit tests, integration tests với MariaDB, secret/Trivy scan, Docker image build và SSH deploy. RAGAS/LangSmith không chạy trong CI public vì cần evaluator secrets và phát sinh chi phí.

Image size đo trên local build:

| Image | Size |
|---|---:|
| `shop_quan_ao-app` | `723 MB` |
| `shop_quan_ao-reranker` | `583 MB` |
| `shop_quan_ao-rag-ml` | `1.87 GB` |

## Offline Evaluation

Cài dependency trong môi trường Python riêng, sau đó export secrets ở shell; không ghi API key vào source hoặc report.

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r eval/requirements-eval.txt

export RAGAS_ENABLE=1
export RAGAS_EMBEDDING_PROVIDER=huggingface
export RAGAS_EMBEDDING_MODEL=bkai-foundation-models/vietnamese-bi-encoder
export RAGAS_ANSWER_RELEVANCY_STRICTNESS=4
export LLM_API_KEY=your-deepseek-api-key
export LLM_BASE_URL=https://api.deepseek.com
export LLM_MODEL=deepseek-chat
export LANGSMITH_API_KEY=your-langsmith-api-key
export LANGSMITH_TRACING=true
export LANGSMITH_PROJECT=fashion-shop-manual-eval

python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --cases eval/chatbot_positive_eval_cases.jsonl \
  --output reports/chatbot_eval_report.json \
  --markdown-output reports/BAO_CAO_CHATBOT_EVAL.md \
  --timeout 90 \
  --turn-delay 5.3
```

`reports/`, environment files, model weights, HuggingFace cache, Python cache và local database dumps đã được loại khỏi Git bằng `.gitignore`.

## Deployment

- Production gateway mặc định bind `${NGINX_HTTP_PORT:-80}:80`; domain HTTP không cần hiển thị `:8090`.
- EC2/VPS cần mở inbound TCP 80 và không có service khác chiếm port này.
- Nginx không cache `POST /api/chatbot`; endpoint này phụ thuộc session, memory, stock và order data.
- `rag-ml` chạy CPU và lazy-load model. Nên warm `/embed` và `/rerank` trước khi benchmark hoặc nhận traffic.
- Product price/stock luôn được refresh từ MariaDB; order status luôn kiểm tra `user_id` ownership.
