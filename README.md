# Fashion Shop Deterministic Hybrid RAG Chatbot

Website bán quần áo viết bằng PHP 8.2, MariaDB và Docker Compose. Chatbot dùng lõi quyết định deterministic bằng PHP để tìm sản phẩm, trả lời chính sách bằng RAG, tư vấn size và tra cứu đơn hàng khi user đã đăng nhập.

Production path không để LLM tự viết SQL. Query của user được tách thành structured filters; PHP tự build query bằng allowlist, lọc constraint và kiểm evidence trước khi trả JSON cho UI.

## Chức năng chính

| Nhóm | Mô tả |
|---|---|
| Product search | Tìm theo loại sản phẩm, category, giá, tồn kho, size, màu và text attributes suy từ `name + description` |
| Product detail | Lấy đúng sản phẩm theo `product_id`, kèm giá, tồn kho, size và màu suy luận |
| Size advice | Tư vấn size theo chiều cao/cân nặng từ bảng `size_guides` |
| Policy RAG | Trả lời đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán từ knowledge base |
| Order status | Yêu cầu đăng nhập và chỉ trả đơn thuộc user hiện tại |
| Guardrails | Không tư vấn phối đồ, không tự thêm giỏ hàng, không checkout hộ |

## Kiến trúc runtime

| Service | Vai trò |
|---|---|
| `nginx` | Public API gateway, reverse proxy, rate limit chatbot |
| `app` | PHP 8.2/Apache nội bộ, website, REST API và chatbot service |
| MCP stdio | TypeScript MCP process nội bộ, được PHP khởi chạy khi chatbot cần gọi tool |
| `db` | MariaDB 10.11, lưu sản phẩm, đơn hàng, chat, memory, logs |
| `redis` | Cache; app fallback sang file cache khi Redis không sẵn sàng |
| `qdrant` | Vector store cho knowledge collection |
| `rag-ml` | FastAPI sidecar cho embedding và knowledge rerank |
| `reranker` | FastAPI sidecar TF-IDF cho product search rerank |

```text
Browser / Chat widget
  -> Nginx
  -> POST /api/chatbot
  -> PHP ChatbotService
      -> ChatbotMemory
      -> IntentResolver
          -> DeterministicIntentParser
          -> ConflictDetector + ConflictResolver
          -> SemanticEntityEnricher (LLM tùy chọn, JSON only)
          -> MergeEngine (không cho ghi đè field đã khóa)
      -> ToolPlanner + PlanValidator (luật PHP)
      -> EvidenceExecutionLoop
          -> McpToolGateway -> MCP server -> PHP application services
              -> search_products / get_product_detail / suggest_size
              -> retrieve_knowledge / get_order_status / get_categories
          -> EvidenceNormalizer
          -> ProductConstraintVerifier
          -> LightweightEvidenceScorer
      -> ResponseGenerator
      -> OnlineValidator generic
  -> JSON response về UI
```

LLM không chọn intent, tool hoặc SQL. Khi parser còn một thuộc tính mô tả như `style`, `occasion` hoặc `avoid`, `SemanticEntityEnricher` có thể đề xuất JSON cho đúng field được cho phép; `MergeEngine` và `ToolPlanner` PHP vẫn giữ quyền quyết định.

## MCP server

MCP là transport mặc định và chỉ phục vụ PHP chatbot. `McpToolGateway` khởi chạy Node MCP server như một child process, sau đó trao đổi JSON-RPC qua stdin/stdout. Không có port MCP, route `/mcp`, OAuth endpoint hay public MCP URL.

Các tool đọc: `search_products`, `get_product_detail`, `suggest_size`, `retrieve_knowledge`, `get_categories`, `get_order_status`, `list_cart`, `list_orders`, `get_order_detail`. Các tool ghi: `add_to_cart`, `update_cart`, `remove_from_cart`, `create_order`.

Các mutation yêu cầu argument `confirmed: true`; `remove_from_cart` và `create_order` được đánh dấu destructive. User ID lấy từ session đăng nhập của PHP rồi truyền cho child process bằng environment riêng, không nằm trong input schema của tool. Capability phối đồ tự động và thanh toán ngoài hệ thống không được expose.

Rollout transport:

```env
CHATBOT_TOOL_TRANSPORT=mcp       # mặc định
# CHATBOT_TOOL_TRANSPORT=shadow  # MCP primary, so sánh output với legacy
# CHATBOT_TOOL_TRANSPORT=internal # rollback tạm thời
MCP_SERVICE_TOKEN=replace-with-a-long-random-secret
MCP_STDIO_NODE=/usr/bin/node
MCP_STDIO_SCRIPT=/opt/mcp-server/dist/stdio.js
```

`MCP_SERVICE_TOKEN` chỉ bảo vệ request loopback từ Node child process tới PHP application services. Nginx trả `404` cho `/api/internal/mcp`; container app không publish port trực tiếp. `MCP_PUBLIC_BASE_URL` không còn được sử dụng.

## Cache hai tầng cho chatbot và website

- **Tầng 1 (client/device):** các GET công khai như sản phẩm, danh mục, FAQ, size guide và knowledge search trả `Cache-Control` cùng `ETag`, vì vậy trình duyệt hoặc app website có thể dùng cache trên thiết bị và gửi `If-None-Match` để nhận `304 Not Modified`.
- **Tầng 2 (server):** các kết quả tool/RAG/LLM của chatbot dùng Redis chung qua `api/cache/Cache.php` (có fallback sang file cache khi Redis tạm thời không khả dụng). TTL và prefix được cấu hình bằng `REDIS_*`.

Response `POST /api/chatbot` không được cache ở client vì chứa session/context cá nhân; dữ liệu mà chatbot truy vấn vẫn được hưởng server-side Redis cache. `CLIENT_CACHE_TTL` và `CLIENT_CACHE_STALE_TTL` điều chỉnh cache HTTP phía website.

Kiểm thử MCP:

```bash
cd mcp-server
npm ci
npm test
npm run build

docker compose up -d --build
curl -X POST http://localhost/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"tìm áo màu đen"}'
```

Tool contract vẫn dùng SDK MCP chính thức; transport local là stdio thay vì remote Streamable HTTP.

## Search và constraint validation

Search sản phẩm dùng hướng structured filters + allowlisted query:

| Constraint | Cách kiểm |
|---|---|
| `category_id`, `min_price`, `max_price`, `in_stock` | SQL/PDO allowlist |
| `size` | `product_sizes` bằng join/subquery |
| `color` | `ProductAttributeNormalizer`, canonical tiếng Việt |
| `material`, `style`, `occasion`, `avoid` | Matcher trên `products.name + products.description` |
| `semantic_query` | Text matcher/rerank, vẫn giữ hard constraints |

Màu được chuẩn hóa về canonical tiếng Việt để tránh lỗi kiểu `đen -> black` rồi không match DB tiếng Việt:

| Input | Canonical |
|---|---|
| `black`, `den`, `đen` | `đen` |
| `white`, `trang`, `trắng` | `trắng` |
| `gray`, `grey`, `xam`, `ghi`, `xám` | `xám` |

`ProductConstraintVerifier` chạy sau `EvidenceNormalizer`, trước `ResponseGenerator`. Nếu user hỏi `áo màu đen size M còn hàng`, từng card phải thỏa đủ loại sản phẩm, màu, size và tồn kho. Nếu không còn card phù hợp, bot phải trả không tìm thấy sản phẩm phù hợp, không được nói tìm thấy tổng số áo chung chung.

Product cards/evidence có thêm `available_sizes` và `available_colors` để UI và evaluator kiểm chứng được.

## API chính

### Chatbot

```http
POST /api/chatbot
Content-Type: application/json

{
  "message": "tìm áo size M màu đen còn hàng",
  "session_token": "optional"
}
```

Response rút gọn:

```json
{
  "message": "Mình tìm thấy 2 sản phẩm áo...",
  "products": [
    {
      "id": 52,
      "name": "Áo Khoác Bomber Kaki Đen",
      "price": 550000,
      "stock": 12,
      "available_sizes": ["S", "M", "L", "XL"],
      "available_colors": ["đen"],
      "url": "/product.php?id=52",
      "image_url": "/images/ak_bomber_03.jpg"
    }
  ],
  "primary_intent": "product_search",
  "response_type": "final_answer",
  "trace_id": "..."
}
```

### Knowledge search

```http
GET /api/knowledge/search?q=shop%20đổi%20trả%20trong%20bao%20lâu&category=return&limit=5
```

## Cài đặt local

```bash
cp .env.example .env
```

Cấu hình tối thiểu:

```env
LLM_PROVIDER=deepseek
LLM_API_KEY=your-deepseek-api-key
LLM_BASE_URL=https://api.deepseek.com
LLM_MODEL=deepseek-chat
NGINX_HTTP_PORT=80
```

Start stack:

```bash
docker compose up -d --build
docker compose ps
```

Nếu port 80 đang bận:

```bash
NGINX_HTTP_PORT=8092 docker compose up -d --build
```

Smoke test:

```bash
curl http://localhost/nginx-health
curl 'http://localhost/api/products?limit=1'

curl -X POST http://localhost/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"tìm áo màu đen"}'
```

Nếu chạy bằng port khác, thay `http://localhost` bằng `http://localhost:8092`.

Index knowledge sau lần deploy đầu hoặc khi đổi tài liệu:

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

## Docker image optimization

Python sidecar images dùng multi-stage build và gom dependency vào `/install`:

```dockerfile
ENV PIP_NO_CACHE_DIR=1
RUN pip install --prefix=/install ...
COPY --from=python-deps /install /usr/local
```

`PIP_NO_CACHE_DIR=1` tránh giữ pip cache trong image. `--prefix=/install` gom thư viện Python ở builder stage để final stage copy đúng phần runtime cần thiết.

## Test và CI

CI GitHub Actions hiện có:

| Job | Nội dung |
|---|---|
| Code quality | composer validate, PHP lint, PHPCS non-blocking, PHPStan, Python syntax |
| Unit tests | PHPUnit Unit suite |
| Integration tests | PHPUnit Integration với MariaDB service |
| Security | secret scan và Trivy filesystem scan |
| Docker | build app/reranker/rag-ml images và Trivy image scan |
| Deploy | SSH deploy khi push `main`/`master` |

Lệnh local thường dùng:

```bash
python3 -m py_compile docker/reranker/app.py docker/rag-ml/app.py eval/ragas_compat.py eval/run_chatbot_eval.py scripts/eval_rag_chatbot.py
docker compose config --quiet

docker run --rm -v "$PWD":/app -w /app -e APP_ENV=test shop_quan_ao-app:latest \
  sh -lc 'php -d memory_limit=512M vendor/bin/phpstan analyse --level=1 api/ config/ --no-progress && vendor/bin/phpunit'
```

Kết quả local gần nhất:

| Check | Kết quả |
|---|---|
| PHP lint | Pass |
| PHPStan level 1 | No errors với `memory_limit=512M` |
| PHPUnit full | `81 tests, 299 assertions` pass sau architecture/SOLID cleanup |
| Integration database | Đã chạy trong full suite với SQLite fallback; MariaDB được CI chạy trên database tách biệt |
| Characterization | Tool choice không đổi khi bật/tắt LLM; cart guardrail không gọi LLM/tool sản phẩm |
| Python syntax | Pass |
| Docker Compose config | Pass |
| HTTP constraint smoke | `áo màu đen`, `áo màu trắng`, `áo size M màu đen còn hàng` pass |

## Offline evaluation: RAGAS và LangSmith

RAGAS/LangSmith chỉ chạy offline/manual, không nằm trong production request và không phải CI gate mặc định vì cần evaluator secrets.

Dependency:

```bash
pip install -r eval/requirements-eval.txt
```

Evaluator secrets phải để trong env hoặc `.env`, không commit vào source:

```env
OPENAI_EVAL_MODEL=deepseek-v4-flash
LLM_API_KEY=your-evaluator-key
LLM_BASE_URL=https://api.deepseek.com
LANGSMITH_API_KEY=your-langsmith-key
LANGSMITH_TRACING=true
LANGSMITH_PROJECT=fashion-shop-chatbot-eval
```

Multi-turn chatbot eval:

```bash
set -a; . ./.env; set +a

RAGAS_ENABLE=1 \
RAGAS_EMBEDDING_PROVIDER=rag_ml \
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
CHATBOT_BASE_URL=http://localhost \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --cases eval/chatbot_eval_cases.jsonl \
  --output reports/chatbot_eval_report_latest.json \
  --markdown-output reports/chatbot_eval_report_latest.md \
  --timeout 90 \
  --turn-delay 6 \
  --max-retries 3
```

Retrieval/chat RAG eval:

```bash
set -a; . ./.env; set +a

EVAL_BASE_URL=http://localhost \
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
python3 scripts/eval_rag_chatbot.py \
  --base-url http://localhost \
  --cases tests/eval/rag_eval_cases.json \
  --out-dir reports/eval \
  --project-name fashion-shop-rag-eval \
  --case-delay 6
```

Kết quả đo gần nhất ngày `2026-08-01`, target `http://localhost` qua Nginx port 80, evaluator `deepseek-v4-flash` và embedding `bkai-foundation-models/vietnamese-bi-encoder`:

| Eval | Kết quả |
|---|---:|
| Chatbot deterministic | `9/9` pass |
| Chatbot latency avg / p95 | `25.78 ms` / `38 ms` |
| Chatbot RAGAS answer relevancy | `0.5098` |
| Chatbot RAGAS faithfulness | `0.7167` |
| Chatbot RAGAS context precision | `0.9861` |
| Chatbot RAGAS context recall | `0.8333` |
| Retrieval/chat cases | `8`, không có retrieval/chat error |
| Retrieval latency avg / p95 | `6.67 ms` / `13.35 ms` |
| Retrieval/chat latency avg / p95 | `385.83 ms` / `1088.52 ms` |
| Retrieval/chat answer keyword coverage | `0.9688` |
| Retrieval/chat RAGAS answer relevancy | `0.3688` (8/8 hợp lệ) |
| Retrieval/chat RAGAS faithfulness | `0.6286` (7/8 hợp lệ) |
| Retrieval/chat RAGAS context precision | `0.6146` |
| Retrieval/chat RAGAS context recall | `0.5000` |
| LangSmith dataset | `fashion-shop-rag-eval-ragml-newkey-20260801-dataset`, `8` examples |

Hai bộ số không được so sánh trực tiếp: bộ chatbot dùng evidence thật gồm knowledge và product cards; bộ retrieval/chat chỉ đưa kết quả `/api/knowledge/search` vào RAGAS. Lần đo mới nhất có một faithfulness timeout trong mỗi bộ; report ghi rõ số mẫu hợp lệ, không thay null bằng số giả.

SRS chuẩn hóa yêu cầu, tiêu chí nghiệm thu và traceability nằm tại [`docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md`](docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md). Báo cáo RAGAS mới nhất nằm tại `reports/ragas_langsmith_latest.md`.

## Bảo mật khi push GitHub

- Không commit `.env`, reports local, model cache hoặc database dump cá nhân.
- `.gitignore` đã loại `.env` và `reports/`.
- CI có bước scan secret dạng `sk-...`, `lsv2_...`, GitHub token và private key.
- Nếu API key từng xuất hiện trong chat/log ngoài repo, nên rotate key trước khi public.

## File quan trọng

| Path | Vai trò |
|---|---|
| `api/controllers/chatbot/ChatbotService.php` | Application service điều phối use case chatbot |
| `api/controllers/chatbot/PdoChatbotConversationStore.php` | Adapter PDO lưu hội thoại và telemetry |
| `api/controllers/chatbot/contracts/` | Contract hẹp cho tool, memory và conversation persistence |
| `api/controllers/chatbot/ToolRegistry.php` | Tool definitions và execute tool |
| `api/controllers/chatbot/ProductAttributeNormalizer.php` | Chuẩn hóa/match màu, size, text attributes |
| `api/controllers/chatbot/pipeline/IntentResolver.php` | Parse, conflict resolution, optional enrichment và merge |
| `api/controllers/chatbot/pipeline/SemanticEntityEnricher.php` | LLM JSON helper tùy chọn; không được chọn tool |
| `api/controllers/chatbot/pipeline/ToolPlanner.php` | Chọn tool hoàn toàn bằng luật PHP |
| `api/controllers/chatbot/pipeline/EvidenceExecutionLoop.php` | Execute/retry tool theo evidence và budget deterministic |
| `api/controllers/chatbot/pipeline/ProductConstraintVerifier.php` | Lọc card theo constraints sau evidence normalization |
| `api/controllers/chatbot/pipeline/ResponseGenerator.php` | Sinh message cuối từ evidence/cards |
| `eval/run_chatbot_eval.py` | Eval multi-turn chatbot + RAGAS |
| `scripts/eval_rag_chatbot.py` | Eval retrieval/chat RAG + LangSmith dataset |
| `eval/ragas_compat.py` | Helper evaluator LLM cho RAGAS, gồm DeepSeek `n=1` fan-out |
| `docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md` | SRS, acceptance criteria và RTM theo ISO/IEC/IEEE 29148:2018 |
