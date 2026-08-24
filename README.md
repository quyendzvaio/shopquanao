# Fashion Shop — Deterministic Hybrid RAG + Styling Agent

Ứng dụng bán quần áo chạy bằng PHP 8.2, MariaDB và Docker Compose. Chatbot dùng luật deterministic để chọn intent/tool, RAG cho chính sách, MCP stdio cho application tools, và FindMine Complete the Look cho hai use case styling.

LLM không tự viết SQL và không tự chọn tool. Dữ liệu trả về cho người dùng phải đi qua Product Search, evidence normalization và validation của PHP.

## Chức năng

| Nhóm | Khả năng |
| --- | --- |
| Product | Tìm kiếm, xem chi tiết, lọc giá/tồn kho/size/màu |
| Size | Tư vấn theo chiều cao, cân nặng và size guide |
| Policy RAG | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| Order | Tra cứu đơn thuộc user đã xác thực; không lộ dữ liệu user khác |
| Guardrail | Không tự checkout/thanh toán; câu phối đồ chung không có sản phẩm neo vẫn bị từ chối |
| UC1 | Khi user yêu cầu phối một `product_id` cụ thể, gọi FindMine rồi chỉ hiển thị SKU thật từ Product Search |
| UC2 | Sau sự kiện thêm giỏ và đủ hai user turns phù hợp, chủ động gợi ý một lần cho anchor mới nhất |

## Kiến trúc runtime

```text
Browser
  -> Nginx
  -> PHP ChatbotService
      -> deterministic IntentResolver
      -> ToolPlanner + PlanValidator
      -> MCP stdio child process
          -> PHP internal application services
              -> Product / Size / Policy / Order tools
              -> FindMine Complete the Look
                  -> strict LLM fashion extraction
                  -> taxonomy normalization
                  -> bounded parallel Product Search
      -> EvidenceNormalizer + ProductConstraintVerifier
      -> ResponseGenerator + OnlineValidator

CartService transaction
  -> fashion_event_outbox
  -> fashion-outbox-publisher
  -> Redis Stream
  -> fashion-event-consumer
  -> proactive_styling_state
  -> second suitable ChatbotService turn -> shared styling pipeline
```

| Service | Vai trò |
| --- | --- |
| `nginx` | Public gateway và chatbot rate limit |
| `app` | PHP/Apache, REST API, chatbot và MCP client |
| `fashion-outbox-publisher` | Publish transactional outbox sang Redis Stream |
| `fashion-event-consumer` | Consume idempotent và cập nhật proactive state |
| `db` | MariaDB 10.11 |
| `redis` | Shared cache và event stream |
| `qdrant` | Knowledge vector store |
| `rag-ml` | Embedding và knowledge rerank |
| `reranker` | Product rerank sidecar |

MCP và FindMine đều dùng private stdio child process, không mở public MCP port. Nginx trả `404` cho `/api/internal/mcp`; app container cũng không publish port trực tiếp.

## Chế độ FindMine

Local/demo dùng response tổng hợp chính thức của FindMine MCP, sau đó chạy extraction thật và Product Search thật của shop:

```env
FASHION_PROVIDER=findmine_demo
FINDMINE_ENABLED=true
FINDMINE_DEMO_ENABLED=true
FINDMINE_DEMO_MODE=true
FINDMINE_LIVE_VERIFIED=false
FINDMINE_APP_ID=DEMO_APP_ID
```

Demo mode không phải bằng chứng tenant production. Live mode cần App ID thật, catalog identifier đã đồng bộ và mapping product/variant/color của tenant. Xem `docs/findmine-live-onboarding-guide.md` và `docs/findmine-provider-contract.md`.

Production deploy mặc định để các cờ FindMine là `false`; phải bật rõ bằng GitHub environment secrets sau khi hoàn tất onboarding hoặc khi chủ động phát hành demo mode.

## Cài đặt local

```bash
cp .env.example .env
```

Điền ít nhất:

```env
LLM_PROVIDER=openai_compatible
LLM_API_KEY=replace-me
LLM_BASE_URL=https://your-openai-compatible-endpoint/v1
LLM_MODEL=your-model
MCP_SERVICE_TOKEN=replace-with-a-long-random-secret
```

Khởi động:

```bash
docker compose up -d --build
docker compose ps
curl -fsS http://localhost/nginx-health
curl -fsS 'http://localhost/api/products?limit=1'
```

Migration được khóa bằng MariaDB advisory lock và theo dõi trong `_migrations`.
Để chạy gate giống production trước khi bật app/workers:

```bash
docker compose up -d --wait db redis
docker compose run --rm --no-deps app php scripts/run_database_migrations.php
```

Nếu port 80 bận:

```bash
NGINX_HTTP_PORT=8092 docker compose up -d --build
```

Index lại knowledge khi thay tài liệu:

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

## API chatbot

```http
POST /api/chatbot
Content-Type: application/json

{
  "message": "Sản phẩm mã 50 phối với gì?",
  "session_token": "optional"
}
```

Response chỉ trả product cards của shop; provider identifiers bị giữ trong provenance nội bộ và không được leak ra UI.

## Kiểm thử

```bash
# PHP
vendor/bin/phpstan analyse --level=1 api/ config/ --no-progress
vendor/bin/phpunit --testsuite=Unit

# MCP TypeScript
npm --prefix mcp-server ci
npm --prefix mcp-server test
npm --prefix mcp-server run build

# Corpus deterministic và Compose
php scripts/run_findmine_offline_eval.php
docker compose config --quiet
```

Agent evaluation đầy đủ gồm 50 HTTP turns cho use case hiện hữu và 20 styling cases (10 UC1 + 10 UC2):

```bash
set -a; . ./.env; set +a

RAGAS_ENABLE=0 \
LANGSMITH_TRACING=true \
LANGSMITH_PROJECT=fashion-shop-chatbot-http-50-final-rerun-20260825 \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --output reports/eval/chatbot_http_50_latest.json \
  --csv-output reports/eval/chatbot_http_50_latest.csv \
  --markdown-output reports/eval/chatbot_http_50_latest.md

docker compose exec -T app \
  php scripts/run_findmine_agent_eval.php \
  --output=/tmp/findmine_agent_eval_latest.json

python3 eval/build_full_agent_eval_report.py \
  --chatbot-report reports/eval/chatbot_http_50_latest.json \
  --styling-report reports/eval/findmine_agent_eval_latest.json \
  --output reports/eval/full_agent_eval_70_latest.json
```

RAGAS cho recommendation answers:

```bash
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
OPENAI_EVAL_MODEL="$LLM_MODEL" \
LANGSMITH_TRACING=true \
LANGSMITH_PROJECT=fashion-shop-findmine-70-final-20260825 \
RAGAS_MAX_WORKERS=4 \
python3 eval/run_findmine_ragas.py \
  --agent-report reports/eval/findmine_agent_eval_latest.json \
  --output reports/eval/findmine_ragas_latest.json
```

## Kết quả cuối — 2026-08-25

Environment: Docker Compose local qua Nginx port 80; LLM/evaluator `oc/mimo-v2.5-free`; embedding evaluator `bkai-foundation-models/vietnamese-bi-encoder` qua `rag-ml`.

### Agent Evaluation 70 câu

| Chỉ số | Kết quả |
| --- | ---: |
| Tổng | `70/70 PASS` |
| Existing use cases qua HTTP | `50/50 PASS` |
| UC1 explicit styling | `10/10 PASS` trong report tổng; suite styling rộng hơn `20/20 PASS` |
| UC2 proactive after cart | `10/10 PASS` trong report tổng; suite styling rộng hơn `20/20 PASS` |
| Stage failures | `0` ở FindMine, extraction, normalization, Product Search, response, event state và grounding |
| Hallucinated shop products | `0` |
| Provider-ID leakage | `0` |
| UC2 sequencing evidence | `20/20`: 2 turns trước call, 0 call trước turn 2, 1 call sau turn 2 |

Coverage của 70 câu: `policy/rag`, `product evidence`, `mixed multi-tool`, `order/auth`, `guardrail/non-rag`, `UC1_EXPLICIT_STYLING`, `UC2_PROACTIVE_AFTER_CART`.

### Latency cuối

Hai boundary được báo riêng vì không cùng ý nghĩa đo:

| Boundary | Avg | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: |
| 50 HTTP turns: client → Nginx → ChatbotService | `8765.06 ms` | `4947 ms` | `41494 ms` | `53139 ms` |
| 10 UC1 direct styling pipeline | `7930.44 ms` | `6504.57 ms` | `19627.65 ms` | `19627.65 ms` |
| 10 UC2 direct styling pipeline | `7296.93 ms` | `7023.57 ms` | `14082.84 ms` | `14082.84 ms` |
| 20 styling recommendation core | `7010.25 ms` | `5923 ms` | `14069 ms` | `18785 ms` |

Styling stages trên 20 cases của report tổng:

| Stage | Avg | p95 | Max |
| --- | ---: | ---: | ---: |
| FindMine demo MCP | `319.65 ms` | `399 ms` | `430 ms` |
| LLM extraction | `771.70 ms` | `546 ms` | `11670 ms` |
| Normalization | `8.70 ms` | `12 ms` | `16 ms` |
| Parallel Product Search | `5910.20 ms` | `10994 ms` | `13543 ms` |

Product Search là bottleneck chính. HTTP p95 cao chủ yếu do entity enrichment và một số policy turns qua evaluator gateway; xem `server_latency` trong report JSON để phân tích từng span.

### RAGAS cuối

RAGAS chấm đủ 40 recommendation answers có Product Search contexts; FindMine prose bị loại khỏi grounding context. Không tính `context_precision`/`context_recall` vì corpus này chưa có reference answers hoặc relevance labels.

| Metric | Điểm |
| --- | ---: |
| Faithfulness | `0.7625` |
| Answer relevancy | `0.1692598273` |

Answer relevancy thấp là kết quả chất lượng thật: nhiều câu hỏi biến thể nhận response template dài và giống nhau. Đây là mục tiêu tối ưu tiếp theo, không phải execution failure.

### LangSmith cuối

| Project/trace | Kết quả |
| --- | --- |
| `fashion-shop-findmine-70-final-20260825` | Successful RAGAS trace `893391a9-8dfa-4563-b88d-dfa2e500ba46`: `241 runs` = `120 LLM` + `121 chain`, `0 error`, `0 pending` |
| `fashion-shop-chatbot-http-50-final-rerun-20260825` | `76 runs` = `50 call_chatbot` + `26 call_knowledge`, `0 error`, `0 pending` |

Secrets chỉ được nạp qua process environment; không được ghi vào report hoặc README.

## CI/CD

GitHub Actions chạy:

- Composer validation, PHP lint, PSR-12 advisory, PHPStan và Python syntax.
- MCP `npm ci`, contract tests và TypeScript build.
- PHPUnit unit/integration, cùng gate corpus offline đúng 70 câu.
- Secret scan, Trivy filesystem/image scan và Docker build cho app/reranker/rag-ml.
- Deploy qua SSH cho `main`/`master`; DB/Redis được chờ healthy và migration phải PASS trước khi app/workers khởi động. FindMine production là opt-in bằng environment secrets.

App và hai worker dùng cùng `${APP_IMAGE:-shop_quan_ao-app:latest}`, vì vậy đổi Compose project name không làm worker trỏ sang image khác.

## Artifacts

| File | Nội dung |
| --- | --- |
| `reports/eval/full_agent_eval_70_latest.json` | Report tổng 70 câu |
| `reports/eval/chatbot_http_50_latest.json` | 50 HTTP turns và server spans |
| `reports/eval/findmine_agent_eval_latest.json` | Suite styling 70 cases và stage latency |
| `reports/eval/findmine_ragas_latest.json` | RAGAS cuối |
| `docs/findmine-use-case-1.md` | UC1 contract |
| `docs/findmine-use-case-2.md` | UC2 contract |
| `docs/cart-styling-event-architecture.md` | Outbox/Redis/consumer architecture |
| `docs/findmine-live-onboarding-guide.md` | Live tenant gate |

Reports, `.env`, model cache và local database artifacts phải giữ ngoài Git. `.gitignore`/`.dockerignore` đã loại các file này khỏi commit và production image.
