# Fashion Shop Agentic RAG Chatbot

Chatbot AI cho website shop quần áo, hỗ trợ tư vấn sản phẩm cơ bản, tư vấn size, tra đơn hàng và hỏi đáp chính sách CSKH dựa trên dữ liệu thật của shop.
Hệ thống dùng kiến trúc Agentic RAG theo hướng production pipeline: RAG Service phụ trách truy xuất tri thức, ReAct/Agent Service phụ trách intent extraction, tool planning, evidence validation và tổng hợp câu trả lời.

## Tóm Tắt

| Hạng mục | Mô tả |
|---|---|
| Bài toán | Chatbot CSKH và tư vấn sản phẩm cho fashion shop |
| Kiến trúc | Nginx API Gateway -> PHP Agent Service -> RAG/Product/Order tools |
| Agent Service | Load memory, phân tích intent, lập kế hoạch tool, chuẩn hóa evidence, sinh answer, validate online |
| RAG Service | Query rewriting, Vietnamese embedding, Qdrant vector search, lexical search, RRF fusion, cross-encoder rerank |
| Phạm vi | Chính sách, sản phẩm, chi tiết sản phẩm, size, trạng thái đơn hàng |
| Không hỗ trợ | Phối đồ, tự thêm giỏ hàng, tự checkout |

## Công Nghệ

| Thành phần | Công nghệ/Kỹ thuật | Vai trò |
|---|---|---|
| API Gateway | Nginx | Reverse proxy, rate limit `/api/chatbot`, chặn file nhạy cảm |
| Backend | PHP 8.2, Apache | API shop và Agent Service |
| Database | MariaDB 10.11 | Products, categories, size guides, orders, FAQ, chat logs |
| Cache | Redis, file fallback | Embedding/retrieval/rerank/product/size cache |
| Vector DB | Qdrant | Collection `shop_knowledge_v2` |
| Embedding | `bkai-foundation-models/vietnamese-bi-encoder` | Vietnamese semantic embedding 768 chiều |
| Knowledge Reranker | `itdainb/PhoRanker` | Cross-encoder rerank policy chunks |
| RAG ML Sidecar | FastAPI, SentenceTransformers, PyTorch CPU | Endpoint `/embed`, `/rerank` |
| Product Reranker | FastAPI, scikit-learn TF-IDF char n-gram | Rerank riêng cho product search |
| LLM Fallback | DeepSeek-compatible OpenAI API format | Dùng khi deterministic pipeline không đủ chắc chắn |
| Evaluation | RAGAS, LangSmith, HuggingFace local embedding | Offline/manual eval, không nằm trong request production |
| Container | Docker Compose | Chạy Nginx, app, DB, Redis, Qdrant, rag-ml, reranker |

## Kiến Trúc

```text
Browser
  -> Nginx API Gateway
      -> PHP App /api/chatbot
          -> ChatbotMemory
          -> AgenticOrchestrator
              -> IntentAndConstraintExtractor
              -> ToolPlanner
              -> ParallelToolExecutor
                  -> retrieve_knowledge -> KnowledgeRetriever -> rag-ml + Qdrant + Markdown/DB
                  -> search_products -> MariaDB + product TF-IDF reranker
                  -> get_product_detail -> MariaDB
                  -> suggest_size -> MariaDB size_guides
                  -> get_order_status -> MariaDB orders
              -> EvidenceNormalizer
              -> ResponseGenerator
              -> OnlineValidator
              -> LLM fallback nếu pipeline không chắc chắn
          -> chat_messages + tool_executions
      -> JSON response
```

Response giữ backward compatibility với frontend cũ:

```json
{
  "message": "Câu trả lời cho UI cũ",
  "products": [],
  "knowledge_sources": [],
  "session_token": "...",
  "session_id": 1,
  "answer": "Câu trả lời chuẩn mới",
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

## Luồng Kỹ Thuật

### Agent Service

```text
User query
  -> load session memory + slot memory
  -> extract primary_intent, secondary_intents, entities, requested_fields
  -> plan tool calls
  -> execute tools
  -> normalize tool outputs thành evidence schema
  -> generate direct answer từ evidence
  -> deterministic online validation
  -> save chat/tool logs
  -> return JSON
```

Các intent chính:

| Intent | Tool chính | Ghi chú |
|---|---|---|
| `product_search` | `search_products` | Tìm sản phẩm theo keyword/category/price/stock |
| `product_detail` | `get_product_detail` | Product ID route thẳng detail, không search list |
| `size_advice` | `suggest_size` | Thiếu chiều cao/cân nặng thì trả clarification |
| `return_exchange`, `shipping`, `policy` | `retrieve_knowledge` | Chỉ trả lời dựa trên policy chunks |
| `mixed_product_policy` | Product tool + `retrieve_knowledge` | Ví dụ sản phẩm còn hàng và đổi size có mất phí |
| `order_status` | `get_order_status` | Guest phải đăng nhập, không cache dài |

### RAG Service

```text
Policy query
  -> query rewriting theo intent
  -> embedding cache lookup
  -> rag-ml /embed nếu cache miss
  -> Qdrant vector search
  -> local lexical search trên Markdown + DB
  -> RRF fusion theo doc_key
  -> retrieval cache
  -> rerank cache lookup
  -> rag-ml /rerank nếu cache miss
  -> top-k chunks
```

Nguồn tri thức:

| Nguồn | Nội dung |
|---|---|
| `knowledge/policies.md` | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| `knowledge/faq.md` | FAQ chính sách |
| `knowledge/shop-info.md` | Thông tin shop |
| `knowledge/size-guide.md` | Hướng dẫn size |
| DB `faqs`, `size_guides` | FAQ và bảng size trong database |

Fallback:

| Lỗi | Cách xử lý |
|---|---|
| `rag-ml` hoặc Qdrant lỗi | Local lexical fallback trên Markdown + DB |
| Reranker lỗi | Trả hybrid candidates với `retrieval_mode=hybrid_no_rerank` |
| Không có context | Trả lời chưa đủ dữ liệu shop, không tự bịa chính sách |

### Product Tools

`search_products` lọc cứng bằng MariaDB và chỉ cache product IDs/ranking ngắn hạn. Khi trả card, hệ thống luôn đọc lại `price` và `stock` mới nhất.

`get_product_detail` cache metadata tĩnh như tên, mô tả, ảnh, size chart; giá và tồn kho luôn refresh từ DB.

`suggest_size` normalize chiều cao/cân nặng, dedupe size rows và trả một recommendation chính.

`get_order_status` đọc trực tiếp từ DB theo `user_id`; guest sẽ nhận yêu cầu đăng nhập.

## Memory

| User type | Memory |
|---|---|
| Guest | Session summary + slot memory |
| Logged-in user | Session summary + slot memory + long-term memory |

Slot memory lưu các trường như `product_type`, `height_cm`, `weight_kg`, `size`, `min_price`, `max_price`. Long-term memory chỉ được nạp khi user có token đăng nhập.

## Cache Policy

| Cache | Key chính | TTL mặc định |
|---|---|---:|
| Embedding | model + preprocess version + query hash | 7 ngày |
| Policy retrieval | query/category/top-k/knowledge version/model | 1 giờ |
| Rerank | query hash + candidate IDs + reranker model | 1 giờ |
| Product search IDs | search/category/price filters | 60 giây |
| Product detail static | product ID | 15 phút |
| Size suggestion | height/weight/category | 10 phút |
| Order status | Không cache dài | 0 giây |

Khi đổi knowledge base, tăng `KNOWLEDGE_VERSION` để cache retrieval/rerank tự tách phiên bản.

## Metrics Đã Đo

Điều kiện đo gần nhất:

| Thuộc tính | Giá trị |
|---|---|
| Ngày đo | 2026-07-16 |
| Target | `http://localhost` qua Nginx |
| Case file | `eval/chatbot_positive_eval_cases.jsonl` |
| Số case | 5 scenario, 25 turns |
| Dữ liệu | Qdrant reindexed `shop_knowledge_v2`, Redis/model cache warm |
| LLM evaluator | DeepSeek-compatible endpoint |
| RAGAS embedding | HuggingFace local `bkai-foundation-models/vietnamese-bi-encoder` |
| LangSmith project | `fashion-shop-production-pipeline-offline-eval-20260716` |
| Network | Tính HTTP localhost qua Nginx, không tính browser network |

### Functional & Latency

| Metric | Giá trị |
|---|---:|
| Deterministic pass | `25/25` |
| Deterministic fail | `0/25` |
| Latency min | `15 ms` |
| Latency avg | `27.28 ms` |
| Latency p50 | `25 ms` |
| Latency p95 | `46 ms` |
| Latency max | `48 ms` |

Ghi chú: Lượt cold/warm-up ngay sau rebuild có avg `579.96 ms`, p95 `2632 ms`, max `4261 ms`; lượt chính thức phía trên chạy sau khi model, retrieval và rerank cache đã warm.

### RAGAS

| Metric | Giá trị |
|---|---:|
| Faithfulness | `0.6844` |
| Answer relevancy | `0.4172` |
| Context precision | `0.9050` |
| Context recall | `0.8500` |

RAGAS contexts gồm policy chunks và evidence chuẩn hóa từ product/order tools khi tool đó thực sự được dùng. RAGAS và LangSmith chỉ chạy offline/manual, không chạy trong CI public và không nằm trong Docker runtime request path.

## Docker Services

| Service | Vai trò | Port |
|---|---|---:|
| `nginx` | API Gateway | `${NGINX_HTTP_PORT:-80}:80` |
| `app` | PHP Agent/API app | internal `80` |
| `db` | MariaDB | `3308:3306` |
| `redis` | Cache | `6379` |
| `qdrant` | VectorDB | internal `6333` |
| `rag-ml` | Embedding + cross-encoder rerank | internal `8000` |
| `reranker` | Product TF-IDF reranker | `8001:8000` |
| `phpmyadmin` | DB admin profile `tools` | `8091:80` |

## Chạy Dự Án

1. Tạo `.env` từ mẫu:

```bash
cp .env.example .env
```

2. Cập nhật các biến bắt buộc:

```env
DB_PASS=...
MARIADB_ROOT_PASSWORD=...
LLM_API_KEY=...
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-chat
NGINX_HTTP_PORT=80
```

Nếu máy local đã có service chiếm port 80, đổi tạm `NGINX_HTTP_PORT=8090` và truy cập `http://localhost:8090`.

3. Start stack:

```bash
docker compose up -d --build
```

4. Reindex knowledge sau khi đổi tài liệu hoặc đổi embedding dimension:

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

5. Smoke test:

```bash
curl http://localhost/api/products?limit=1
curl -X POST http://localhost/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"áo thun giá rẻ"}'
```

## Test

```bash
docker compose run --rm --no-deps -v "$PWD":/work -w /work app \
  sh -lc 'php vendor/bin/phpunit'

docker compose run --rm --no-deps -v "$PWD":/work -w /work app \
  sh -lc 'php vendor/bin/phpstan analyse --no-progress --memory-limit=512M'

docker compose config
```

Kết quả gần nhất:

| Check | Kết quả |
|---|---|
| PHPUnit | `86 tests, 316 assertions` pass |
| PHPStan | No errors |
| Docker Compose config | OK |

## Offline Evaluation

RAGAS/LangSmith chạy thủ công một lần khi cần benchmark, không commit API key và không ghi secret vào report.

```bash
export RAGAS_ENABLE=1
export RAGAS_EMBEDDING_PROVIDER=huggingface
export RAGAS_EMBEDDING_MODEL=bkai-foundation-models/vietnamese-bi-encoder
export LANGSMITH_PROJECT=fashion-shop-production-pipeline-offline-eval

python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --cases eval/chatbot_positive_eval_cases.jsonl \
  --output reports/chatbot_eval_report.json \
  --markdown-output reports/BAO_CAO_CHATBOT_EVAL.md \
  --timeout 90 \
  --turn-delay 5.3
```

Các report trong `reports/` đã được ignore để tránh đẩy benchmark artefacts hoặc log nhạy cảm lên GitHub.

## Repository Hygiene

`.gitignore` và `.dockerignore` loại trừ:

| Nhóm | Ví dụ |
|---|---|
| Secret/env | `.env`, `.env.*`, trừ `.env.example` |
| Reports/evidence | `reports/`, `*_report.*`, `ragas_*.json`, `langsmith_*.json` |
| Model/cache | `.cache/`, `.huggingface/`, `hf_cache/`, `models/`, `*.safetensors`, `*.bin`, `*.pt` |
| Python cache | `__pycache__/`, `.pytest_cache/`, `.mypy_cache/`, `.ruff_cache/` |
| DB/archive | `*.db`, `*.sqlite`, `*.sql.gz`, `*.dump`, `*.tar.gz`, `*.zip` |

## Production Notes

- Không cache `POST /api/chatbot` ở Nginx vì response phụ thuộc user/session/memory/stock/order.
- Product price/stock luôn refresh từ DB trước khi trả response.
- Order status không dùng semantic cache và luôn kiểm tra ownership theo `user_id`.
- `rag-ml` chạy CPU, cold start phụ thuộc tốc độ tải/warm model; nên warm service trước khi benchmark production.
- `ParallelToolExecutor` hiện có batch/dependency abstraction trong PHP request; có thể nâng cấp lên true concurrent HTTP/curl_multi nếu cần throughput cao hơn.
