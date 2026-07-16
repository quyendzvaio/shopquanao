# Fashion Shop Agentic RAG Chatbot

Chatbot AI cho website shop quần áo, hỗ trợ hỏi đáp chính sách CSKH, tìm sản phẩm cơ bản, xem chi tiết sản phẩm, tư vấn size và tra trạng thái đơn hàng.
Hệ thống dùng kiến trúc Nginx API Gateway, PHP Agent Service và Knowledge RAG Service; pipeline ưu tiên xử lý deterministic trước, chỉ gọi LLM khi cần semantic completion hoặc fallback.

## Tổng Quan

| Hạng mục | Mô tả |
|---|---|
| Bài toán | Chatbot CSKH và tư vấn sản phẩm cơ bản cho fashion shop |
| Service chính | ReAct/Agent Service và Knowledge RAG Service |
| Agent Service | Nhận câu hỏi, load memory, parse intent/constraint, lập kế hoạch tool, gọi tool, chuẩn hóa evidence, sinh câu trả lời và validate online |
| RAG Service | Ingest tài liệu shop, embedding, hybrid retrieval, reranking và trả knowledge chunks cho agent |
| Gateway | Nginx reverse proxy public port `80`, rate limit `/api/chatbot`, proxy vào PHP app nội bộ |
| Phạm vi hỗ trợ | Chính sách đổi trả/giao hàng/bảo hành, tìm sản phẩm, chi tiết sản phẩm, size, order status |
| Không hỗ trợ | Phối đồ, tự thêm giỏ hàng, tự checkout |

## Công Nghệ

| Thành phần | Công nghệ/Kỹ thuật | Vai trò |
|---|---|---|
| API Gateway | Nginx | Reverse proxy, rate limit, chặn file nhạy cảm |
| Backend | PHP 8.2, Apache | Shop API và Agent Service |
| Database | MariaDB 10.11 | Products, categories, size guides, orders, FAQ, chat logs |
| Cache | Redis, file fallback | Cache embedding, retrieval, rerank, product IDs, size result |
| Vector DB | Qdrant | Collection `shop_knowledge_v2` cho policy chunks |
| Knowledge Embedding | `bkai-foundation-models/vietnamese-bi-encoder` | Vietnamese semantic embedding 768 chiều |
| Knowledge Reranker | `itdainb/PhoRanker` | Cross-encoder rerank cho knowledge chunks |
| RAG ML Sidecar | FastAPI, SentenceTransformers, PyTorch CPU | Endpoint `/embed` và `/rerank` |
| Product Reranker | FastAPI, scikit-learn TF-IDF char n-gram | Rerank riêng cho product search |
| LLM | DeepSeek-compatible OpenAI API format | Semantic completion/fallback, không nằm trong mọi request |
| Eval offline | RAGAS, LangSmith, HuggingFace local embedding | Benchmark thủ công, không chạy trong CI public |
| Container | Docker Compose | Nginx, app, DB, Redis, Qdrant, rag-ml, reranker |

## Kiến Trúc Hệ Thống

```text
Browser
  -> Nginx API Gateway :80
      -> PHP App /api/chatbot
          -> ChatbotMemory
          -> AgenticOrchestrator
              -> FastParser
              -> PartialParseResult
              -> ConflictDetector / ConflictResolver
              -> LLMSemanticCompletion nếu còn unresolved spans
              -> MergeEngine
              -> CapabilityRegistry
              -> ToolPlanner
              -> PlanValidator
              -> ParallelToolExecutor
                  -> retrieve_knowledge -> KnowledgeRetriever -> rag-ml + Qdrant + Markdown/DB
                  -> search_products -> MariaDB + product TF-IDF reranker
                  -> get_product_detail -> MariaDB
                  -> suggest_size -> MariaDB size_guides
                  -> get_order_status -> MariaDB orders
              -> EvidenceNormalizer
              -> ResponseGenerator
              -> OnlineValidator
          -> chat_messages + tool_executions
      -> JSON response
```

## Luồng Request Chi Tiết

1. **Browser gửi message** - Frontend chatbox gọi `POST /api/chatbot` qua Nginx - Nginx áp rate limit và proxy sang PHP app - Giúp giữ một entrypoint production thay vì expose app trực tiếp.
2. **Load memory** - Backend đọc session summary và slot memory; long-term memory chỉ dùng khi user đã đăng nhập - Dữ liệu này giúp câu hỏi tiếp theo hiểu được ngữ cảnh như sản phẩm đang hỏi hoặc size đã cung cấp.
3. **Fast parse** - `FastParser` trích xuất nhanh intent, product ID, size, chiều cao/cân nặng, khoảng giá, stock và policy keywords - Giảm số lần gọi LLM cho các câu hỏi phổ biến như "áo thun giá rẻ" hoặc "sản phẩm mã 52".
4. **Resolve conflict và semantic completion** - `ConflictDetector`, `ConflictResolver`, `LLMSemanticCompletion` chỉ xử lý phần còn mơ hồ - Cách này giữ các field đã chắc chắn và chỉ dùng LLM cho phần semantic như dịp mặc, style hoặc yêu cầu tránh.
5. **Merge và validate plan** - `MergeEngine`, `CapabilityRegistry`, `ToolPlanner`, `PlanValidator` tạo danh sách tool call hợp lệ - Product ID được route thẳng sang `get_product_detail`, không bị chuyển thành search query.
6. **Execute tools** - `ParallelToolExecutor` chạy các tool theo kế hoạch - Mixed intent có thể gọi cả product tool và `retrieve_knowledge`.
7. **Normalize evidence** - `EvidenceNormalizer` chuẩn hóa output thành facts như `product_id`, `price`, `stock`, `policy_condition`, `source` - Evidence này dùng cho response và eval.
8. **Generate answer** - `ResponseGenerator` sinh câu trả lời trực tiếp từ evidence - Product cards vẫn giữ format cũ để frontend render.
9. **Online validation** - `OnlineValidator` kiểm tra hard constraints như product ID, giá, tồn kho, auth/ownership đơn hàng - Nếu fail thì trả fallback/clarification an toàn.
10. **Persist logs** - Lưu `chat_messages`, `tool_executions`, metadata routing/evaluation và latency - Phục vụ debug, benchmark và regression analysis.

## Response Contract

Response vẫn tương thích frontend cũ bằng các field `message`, `products`, `knowledge_sources`, đồng thời bổ sung shape mới cho pipeline production.

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

## Tool Và Capability

| Tool | Nhiệm vụ | Data source |
|---|---|---|
| `retrieve_knowledge` | Truy xuất chính sách, FAQ, size guide, thông tin shop | Markdown knowledge, DB FAQ/size, Qdrant |
| `search_products` | Tìm sản phẩm theo keyword/category/price/stock/size | MariaDB products/categories |
| `get_product_detail` | Lấy chi tiết một sản phẩm theo ID | MariaDB products |
| `suggest_size` | Tư vấn size từ chiều cao/cân nặng/category | MariaDB size guides |
| `get_order_status` | Tra trạng thái đơn hàng của user đăng nhập | MariaDB orders |
| `get_categories` | Lấy danh mục để hỗ trợ lọc sản phẩm | MariaDB categories |

Các tool đã loại khỏi chatbot: `get_outfit`, `prepare_checkout`, `get_faq`. Khi user muốn mua hoặc thanh toán, chatbot hướng dẫn bấm vào card/trang chi tiết sản phẩm thay vì tự checkout.

## Knowledge RAG Service

### Nguồn Dữ Liệu

| Nguồn | Nội dung |
|---|---|
| `knowledge/policies.md` | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| `knowledge/faq.md` | Câu hỏi thường gặp |
| `knowledge/shop-info.md` | Thông tin shop |
| `knowledge/size-guide.md` | Hướng dẫn size |
| DB `faqs`, `size_guides` | FAQ và bảng quy đổi size trong database |

### Retrieval Flow

```text
query
  -> query rewriting
  -> embedding cache lookup
  -> rag-ml /embed nếu cache miss
  -> Qdrant vector search top candidates
  -> lexical search trên Markdown + DB
  -> RRF fusion theo doc key
  -> retrieval cache
  -> rerank cache lookup
  -> rag-ml /rerank nếu cache miss
  -> top-k knowledge chunks
```

Output của retrieval giữ `title`, `content`, `category`, `source`, `updated_at`, `vector_score`, `lexical_score`, `hybrid_score`, `rerank_score`, `retrieval_mode`.

### Fallback

| Sự cố | Cách xử lý |
|---|---|
| `rag-ml` lỗi | Dùng lexical fallback trên Markdown + DB |
| Qdrant lỗi hoặc chưa ingest | Dùng lexical fallback và gắn `retrieval_mode=lexical_fallback` |
| Reranker lỗi | Trả hybrid candidates và gắn `retrieval_mode=hybrid_no_rerank` |
| Không có context | Không tự bịa chính sách; trả lời thiếu dữ liệu và hướng dẫn liên hệ shop |

## Product Và Order Flow

| Chức năng | Cách xử lý |
|---|---|
| Product search | Lọc bằng SQL trước, rerank sản phẩm bằng TF-IDF sidecar, chỉ cache IDs ngắn hạn |
| Product detail | Product ID route thẳng `get_product_detail`, trả đúng một product card |
| Product card | Dùng relative URL `/product.php?id={id}` và `/images/{image}` để chạy đúng sau Nginx/domain |
| Price/stock | Luôn refresh từ MariaDB trước khi trả response, không cache dài |
| Size advice | Dùng chiều cao/cân nặng/category; thiếu slot thì hỏi lại |
| Order status | Guest phải đăng nhập; user chỉ xem được đơn của chính mình |

## Memory

| User type | Memory được dùng |
|---|---|
| Guest | Session summary + slot memory |
| Logged-in user | Session summary + slot memory + long-term memory |

Slot memory lưu các trường như `last_product_id`, `product_type`, `height_cm`, `weight_kg`, `size`, `min_price`, `max_price`. Long-term memory chỉ nạp khi request có token/user hợp lệ.

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

Khi đổi tài liệu hoặc embedding dimension, cần reindex Qdrant và tăng knowledge/cache version nếu có.

## Metrics Đã Đo

Điều kiện đo gần nhất:

| Thuộc tính | Giá trị |
|---|---|
| Ngày đo | 2026-07-16 |
| Target | `http://localhost` qua Nginx port `80` |
| Case file | `eval/chatbot_positive_eval_cases.jsonl` |
| Số case | 5 scenario, 25 turns |
| Dataset | Positive cases, không gồm guardrail/refusal cases |
| Knowledge index | Qdrant collection `shop_knowledge_v2` |
| Embedding eval | HuggingFace local `bkai-foundation-models/vietnamese-bi-encoder` |
| LangSmith project | `fashion-shop-ragas-langsmith-port80-20260716` |
| Network | HTTP localhost qua Nginx; không tính browser/client internet |

### Functional Eval

| Metric | Giá trị |
|---|---:|
| Deterministic pass | `25/25` |
| Deterministic fail | `0/25` |
| Latency min | `18 ms` |
| Latency avg | `491.4 ms` |
| Latency p50 | `312 ms` |
| Latency p95 | `1156 ms` |
| Latency max | `3030 ms` |

RAG/policy turns kéo latency cao hơn product/size turns vì phải qua embedding, vector/lexical retrieval và reranking. Product basic warm request đo riêng khoảng `17.956 ms` trung bình qua Nginx localhost.

### RAGAS

| Metric | Giá trị |
|---|---:|
| Faithfulness | `0.6997` |
| Answer relevancy | `0.4029` |
| Context precision | `0.9161` |
| Context recall | `0.8500` |

RAGAS contexts đã hợp nhất policy chunks và evidence chuẩn hóa từ product/order tools khi các tool đó thực sự được dùng. RAGAS chạy offline/manual, không nằm trong request production và không chạy bắt buộc trong CI public.

### Network Timing

Cold/mixed timing sau deploy hoặc khi cache chưa ổn định:

| Endpoint | Avg total | Max/P95 | Ghi chú |
|---|---:|---:|---|
| `nginx_health` | `0.579 ms` | `0.618 ms` | Gateway local |
| `products_api` | `3.232 ms` | `3.781 ms` | Product API |
| `knowledge_api` | `487.913 ms` | `2425.225 ms` | Có retrieval/rerank cold path |
| `chatbot_product` | `47.906 ms` | `158.673 ms` | Product chatbot request |

Warm timing:

| Endpoint | Avg total | Max |
|---|---:|---:|
| `knowledge_api_warm` | `3.452 ms` | `3.869 ms` |
| `chatbot_product_warm` | `17.956 ms` | `18.711 ms` |
| `chatbot_partial_llm_warm` | `398.182 ms` | `1150.157 ms` |

## Test Và CI

Lệnh kiểm thử local:

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

CI/CD không chạy RAGAS/LangSmith vì cần secret evaluator và có chi phí/latency. Deploy healthcheck gọi `http://localhost/api/products?limit=1` qua Nginx port `80`.

## Chạy Dự Án

1. Tạo `.env`:

```bash
cp .env.example .env
```

2. Cập nhật biến bắt buộc:

```env
DB_PASS=...
MARIADB_ROOT_PASSWORD=...
LLM_API_KEY=...
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-chat
NGINX_HTTP_PORT=80
```

Nếu máy local đã có service chiếm port 80, chạy bằng port khác:

```bash
NGINX_HTTP_PORT=8090 docker compose up -d --build
```

3. Start production-style stack:

```bash
docker compose up -d --build
```

4. Reindex knowledge sau khi đổi tài liệu hoặc embedding model:

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

Trên VPS/domain, mở inbound TCP `80` và trỏ DNS A record về public IP. Sau khi deploy đúng port 80, URL production có dạng `http://hostshop.work.gd/` thay vì phải ghi `:8090`.

## Offline Evaluation

RAGAS/LangSmith chỉ chạy thủ công khi cần benchmark, không commit API key và không ghi secret vào output.

```bash
export RAGAS_ENABLE=1
export RAGAS_EMBEDDING_PROVIDER=huggingface
export RAGAS_EMBEDDING_MODEL=bkai-foundation-models/vietnamese-bi-encoder
export LANGSMITH_TRACING=true
export LANGSMITH_PROJECT=fashion-shop-ragas-langsmith-port80-20260716

python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --cases eval/chatbot_positive_eval_cases.jsonl \
  --output reports/chatbot_eval_report.json \
  --markdown-output reports/BAO_CAO_CHATBOT_EVAL.md \
  --timeout 90 \
  --turn-delay 5.3
```

Các report trong `reports/` được ignore để tránh đẩy benchmark artefacts hoặc log nhạy cảm lên GitHub.

## Repository Hygiene

`.gitignore` và `.dockerignore` loại trừ:

| Nhóm | Ví dụ |
|---|---|
| Secret/env | `.env`, `.env.*`, trừ `.env.example` |
| Reports/evidence | `reports/`, `*_report.*`, `ragas_*.json`, `langsmith_*.json` |
| Model/cache | `.cache/`, `.huggingface/`, `hf_cache/`, `models/`, `*.safetensors`, `*.bin`, `*.pt` |
| Python cache | `__pycache__/`, `.pytest_cache/`, `.mypy_cache`, `.ruff_cache` |
| DB/archive | `*.db`, `*.sqlite`, `*.sql.gz`, `*.dump`, `*.tar.gz`, `*.zip` |

## Production Notes

- Không cache `POST /api/chatbot` ở Nginx vì response phụ thuộc user/session/memory/stock/order.
- Product price và stock luôn refresh từ MariaDB trước khi trả card.
- Product card dùng relative URL để không bị lỗi `localhost` sau reverse proxy.
- Order status không dùng semantic cache và luôn kiểm tra ownership theo `user_id`.
- `rag-ml` chạy CPU; cần warm model/cache trước khi benchmark hoặc trước giờ cao điểm.
- `ParallelToolExecutor` hiện có batch/dependency abstraction trong PHP request; có thể nâng cấp true concurrent HTTP/curl_multi nếu cần throughput cao hơn.
