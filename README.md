# Fashion Shop — Deterministic Hybrid RAG + Styling Agent

Ứng dụng bán quần áo chạy bằng PHP 8.2, MariaDB và Docker Compose. Chatbot dùng luật deterministic để chọn intent/tool, RAG cho chính sách, MCP stdio cho application tools, và Stylitics làm nguồn tham chiếu styling. Sản phẩm cuối cùng luôn lấy từ private shop catalog/Product Search.

LLM không tự viết SQL và không tự chọn tool. Dữ liệu trả về cho người dùng phải đi qua Product Search, evidence normalization và validation của PHP.

## Chức năng

| Nhóm | Khả năng |
| --- | --- |
| Product | Tìm kiếm, xem chi tiết, lọc giá/tồn kho/size/màu |
| Size | Tư vấn theo chiều cao, cân nặng và size guide |
| Policy RAG | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| Order | Tra cứu đơn thuộc user đã xác thực; không lộ dữ liệu user khác |
| Guardrail | Không tự checkout/thanh toán; câu phối đồ chung không có sản phẩm neo vẫn bị từ chối |
| UC1 | Khi user yêu cầu phối một `product_id` cụ thể, lấy Stylitics styling references rồi chỉ hiển thị SKU thật từ Product Search |
| UC2 | Sau sự kiện thêm giỏ và đủ hai user turns phù hợp, lấy Stylitics references và chủ động gợi ý SKU private một lần cho anchor mới nhất |

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
              -> StylingReferenceProvider (Stylitics)
                  -> reference normalization
                  -> hard-filtered, bounded parallel Product Search
      -> EvidenceNormalizer + ProductConstraintVerifier
      -> grounded ResponseGenerator -> native LLM token stream

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

MCP dùng private child process, không mở public MCP port. Stylitics chỉ cung cấp reference/outfit intent; Nginx trả `404` cho `/api/internal/mcp`; app container cũng không publish port trực tiếp.

Chat widget nhận phản hồi qua WebSocket cùng origin tại `/ws/chatbot`.
`chat-stream` chỉ stream text sau khi PHP validator hoàn tất và chỉ gửi private
shop cards đã allow-list; không stream raw Stylitics/MCP payload hoặc provider ID.

## Chế độ Stylitics

Stylitics demo tạo styling references cục bộ để kiểm thử; live mode chỉ được bật khi endpoint, authentication và tool schema đã được vendor xác nhận:

```env
STYLING_PROVIDER=stylitics
STYLITICS_ENABLED=true
STYLITICS_PROVIDER_MODE=demo
STYLITICS_LIVE_VERIFIED=false
```

Demo mode không phải bằng chứng Stylitics production. Live mode cần thông tin endpoint/auth/tool schema thật; hiện trạng vendor gate là `BLOCKED`, không có claim live.

Production deploy mặc định để Stylitics live `disabled`; chỉ bật bằng environment secrets sau khi vendor cung cấp endpoint/auth/tool contract.

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

Agent evaluation styling hiện chạy balanced 50 cases từ corpus nguồn 70 cases:
15 UC1 explicit, 15 UC2 proactive, 10 suppression và 10 unrelated.

```bash
set -a; . ./.env; set +a

RAGAS_ENABLE=0 \
LANGFUSE_PUBLIC_KEY="$LANGFUSE_PUBLIC_KEY" \
LANGFUSE_SECRET_KEY="$LANGFUSE_SECRET_KEY" \
LANGFUSE_BASE_URL="${LANGFUSE_BASE_URL:-http://localhost:3000}" \
python3 eval/run_chatbot_eval.py \
  --base-url http://localhost \
  --output reports/eval/chatbot_http_50_latest.json \
  --csv-output reports/eval/chatbot_http_50_latest.csv \
  --markdown-output reports/eval/chatbot_http_50_latest.md

php scripts/run_stylitics_agent_eval.php \
  --cases=50 --anchor-product-id=57 \
  --output=reports/eval/stylitics_agent_eval_50_live_after_fix_20260830.json
```

RAGAS cho recommendation answers:

```bash
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
OPENAI_EVAL_MODEL="$LLM_MODEL" LLM_TIMEOUT=120 \
python3 eval/run_findmine_ragas.py --max-cases=10 \
  --agent-report reports/eval/stylitics_agent_eval_50_live_after_fix_20260830.json \
  --output reports/eval/stylitics_ragas_10_live_after_fix_20260830.json
```

RAGAS final live parameters: `RAGAS_MODE=STYLITICS_LIVE_REAL_SHOP_RETRIEVAL`,
evaluator `oc/mimo-v2.5-free`, embedding `bkai-foundation-models/vietnamese-bi-encoder`
via `rag-ml`, judge concurrency `1`, 30 available recommendation cases and 10
evaluated cases. Scores: faithfulness `0.3416666667`, answer relevancy
`0.1230097772`. Context precision/recall are omitted because no reference labels
exist. These scores are a quality baseline and must not be interpreted as a
production SLA.

### Langfuse tracing

Langfuse is self-hosted as an opt-in Docker Compose profile. Start it with:

```bash
docker compose --profile observability up -d langfuse-postgres langfuse-clickhouse \
  langfuse-minio langfuse-redis langfuse-web langfuse-worker langfuse-trace-publisher
```

The profile is intentionally separate from `app`, `rag-ml` and `reranker`; the
default `docker compose up` does not pull or start ClickHouse/MinIO. Check it:

```bash
docker compose --profile observability ps
curl -fsS http://localhost:3000/ >/dev/null && echo "Langfuse UI is ready"
```

Before starting on a new host, validate the Postgres filesystem with
`scripts/langfuse-storage-check.sh`. Storage layout, persistence semantics,
backup/recovery, and the guarded reset command are documented in
[`docs/langfuse-observability.md`](docs/langfuse-observability.md).

Open `http://localhost:3000`, create a project, then put only the generated
public/secret project keys in the ignored `.env` as `LANGFUSE_PUBLIC_KEY` and
`LANGFUSE_SECRET_KEY`. Set `LANGFUSE_ENABLED=true`,
`LANGFUSE_BASE_URL=http://localhost:3000` and
`LANGFUSE_INGESTION_URL=http://langfuse-web:3000`,
`LANGFUSE_PROJECT=fashion-shop-chatbot-eval`. Never commit these values or bake
them into an image. The evaluator emits sanitized traces with use-case, provider
mode, anchor ID, reference count, private candidate count, mapping/fallback
flags and stage latency.

To publish the sanitized live Stylitics run (after installing
`eval/requirements-eval.txt`), use:

```bash
python3 eval/publish_stylitics_langfuse.py \
  --report reports/eval/stylitics_agent_eval_50_live_after_fix_20260830.json
```

The command requires the three Langfuse runtime variables above and is
idempotent for the fixed dataset item IDs. It never sends raw MCP responses,
OAuth material, cookies or secret headers. For an isolated local sandbox, the
Compose defaults are sufficient; replace every `local-only-change-me` value in
`.env` before sharing the stack or exposing it beyond localhost. Generate a
64-character encryption key with `openssl rand -hex 32`.

The live styling report was published to dataset
`shopquanao-stylitics-live-20260830` (30 examples) and experiment
`shopquanao-stylitics-live-eval-20260830` (30 runs). The source is explicitly marked
`post_run_evaluation_report`; no provider payloads or credentials are stored.

## Kết quả cuối — 2026-08-26

Environment: Docker Compose local qua Nginx port 80; LLM/evaluator `oc/mimo-v2.5-free`; embedding evaluator `bkai-foundation-models/vietnamese-bi-encoder` qua `rag-ml`.

### Agent Evaluation 50 câu

| Chỉ số | Kết quả |
| --- | ---: |
| Tổng | `50/50 PASS` |
| UC1 explicit styling | `15/15 PASS` |
| UC2 proactive after cart | `15/15 PASS` |
| Suppression / unrelated | `20/20 PASS` |
| Stage failures | `0` ở styling reference, extraction, normalization, Product Search, response, event state và grounding |
| Hallucinated shop products | `0` |
| Provider-ID leakage | `0` |
| UC2 sequencing evidence | `15/15`: 2 turns trước call, 0 call trước turn 2, 1 call sau turn 2 |

Corpus styling 70 câu vẫn được giữ để mở rộng; lệnh mặc định chọn balanced 50 câu nêu trên.

### Latency cuối

Hai boundary được báo riêng vì không cùng ý nghĩa đo:

| Boundary | Avg | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: |
| 50 styling cases | `5816.26 ms` | `7101.04 ms` | `14318.11 ms` | `14920.06 ms` |
| 15 UC1 explicit | `9747.18 ms` | `7964.45 ms` | `14920.06 ms` | `14920.06 ms` |
| 15 UC2 proactive | `9640.29 ms` | `10176.62 ms` | `14318.11 ms` | `14318.11 ms` |
| 10 suppression | `0.05 ms` | `0.04 ms` | `0.10 ms` | `0.10 ms` |
| 10 unrelated | `0.06 ms` | `0.04 ms` | `0.11 ms` | `0.11 ms` |

Styling stages trên 30 recommendation cases:

| Stage | Avg | p95 | Max |
| --- | ---: | ---: | ---: |
| Stylitics demo reference provider | `321.43 ms` | `383 ms` | `430 ms` |
| LLM extraction | `174.63 ms` | `461 ms` | `488 ms` |
| Normalization | `7.97 ms` | `14 ms` | `14 ms` |
| Parallel Product Search | `8683.00 ms` | `13126 ms` | `13699 ms` |

Product Search là bottleneck chính. HTTP p95 cao chủ yếu do entity enrichment và một số policy turns qua evaluator gateway; xem `server_latency` trong report JSON để phân tích từng span.

### RAGAS cuối

RAGAS chấm 2/30 recommendation answers (bounded `--max-cases=2`); Stylitics reference prose bị loại khỏi grounding context. Không tính `context_precision`/`context_recall` vì corpus này chưa có reference answers hoặc relevance labels.

| Metric | Điểm |
| --- | ---: |
| Faithfulness | `0.75` |
| Answer relevancy | `0.1501948092` |

Answer relevancy thấp là kết quả chất lượng thật: nhiều câu hỏi biến thể nhận response template dài và giống nhau. Đây là mục tiêu tối ưu tiếp theo, không phải execution failure.

## CI/CD

GitHub Actions chạy:

- Composer validation, PHP lint, PSR-12 advisory, PHPStan và Python syntax.
- MCP `npm ci`, contract tests và TypeScript build.
- PHPUnit unit/integration, cùng gate corpus offline nguồn 70 câu.
- Secret scan, Trivy filesystem/image scan và Docker build cho app/reranker/rag-ml.
- Deploy qua SSH cho `main`/`master`; DB/Redis được chờ healthy và migration phải PASS trước khi app/workers khởi động. Stylitics live là opt-in bằng environment secrets.

App và hai worker dùng cùng `${APP_IMAGE:-shop_quan_ao-app:latest}`, vì vậy đổi Compose project name không làm worker trỏ sang image khác.

## Artifacts

| File | Nội dung |
| --- | --- |
| `reports/eval/stylitics_agent_eval_50_live_after_fix_20260830.json` | Live 50-case Stylitics evaluation và stage latency |
| `reports/eval/stylitics_ragas_10_live_after_fix_20260830.json` | RAGAS live (10/30 sampled cases) |
| `docs/findmine-agent-evaluation-results.md` | Bảng PASS/latency của 50 cases |
| `docs/findmine-ragas-results.md` | RAGAS metrics và Langfuse parameters |
| `docs/findmine-use-case-1.md` | UC1 contract |
| `docs/findmine-use-case-2.md` | UC2 contract |
| `docs/cart-styling-event-architecture.md` | Outbox/Redis/consumer architecture |
| `docs/findmine-live-onboarding-guide.md` | Live tenant gate |

Reports, `.env`, model cache và local database artifacts phải giữ ngoài Git. `.gitignore`/`.dockerignore` đã loại các file này khỏi commit và production image.
