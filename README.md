# Fashion Shop ReAct RAG Chatbot

Chatbot AI cho website shop quần áo, hỗ trợ CSKH/chính sách và tư vấn sản phẩm cơ bản dựa trên dữ liệu thật của shop.
Hệ thống dùng kiến trúc ReAct Agent kết hợp RAG: RAG Service phụ trách truy xuất tri thức, ReAct Agent Service phụ trách reasoning, chọn tool nghiệp vụ và tổng hợp câu trả lời.

## Tóm Tắt Dự Án

| Hạng mục | Mô tả |
|---|---|
| Bài toán | Chatbot tư vấn sản phẩm, size, trạng thái đơn hàng và chính sách đổi trả/giao hàng/bảo hành cho web shop thời trang |
| Kiến trúc AI | ReAct Agent + RAG + tool calling + self-evaluation |
| RAG Service | Query rewriting, semantic embedding, hybrid retrieval, cross-encoder reranking, context selection |
| ReAct Agent Service | Nhận user query, phân tích intent, gọi tool, kiểm chứng kết quả, trả `message`, `products`, `knowledge_sources` |
| Phạm vi hỗ trợ | Chính sách/CSKH, tìm sản phẩm, xem chi tiết sản phẩm, tư vấn size, tra đơn hàng nếu đã đăng nhập |
| Không hỗ trợ | Tư vấn phối đồ, tự thêm giỏ hàng, tự checkout, hiển thị raw URL |

## Công Nghệ Sử Dụng

| Thành phần | Công nghệ/Kỹ thuật | Vai trò |
|---|---|---|
| Backend API | PHP 8.2, Apache | API web shop và chatbot |
| API Gateway | Nginx | Reverse proxy, public port `8090`, rate limit `/api/chatbot` |
| Database | MariaDB 10.11 | Sản phẩm, danh mục, size guide, đơn hàng, FAQ, chat logs |
| Cache | Redis, file fallback | Cache search/detail/retrieval |
| LLM | DeepSeek-compatible OpenAI API format | Reasoning, tool calling, answer synthesis |
| VectorDB | Qdrant | Lưu vector index `shop_knowledge_v2` |
| Knowledge Embedding | `bkai-foundation-models/vietnamese-bi-encoder` | Embedding tiếng Việt 768 chiều cho policy chunks |
| Knowledge Reranker | `itdainb/PhoRanker` | Cross-encoder rerank top candidates |
| RAG ML Service | FastAPI, SentenceTransformers, Transformers, PyTorch CPU | `/embed`, `/rerank`, model warmup |
| Product Reranker | FastAPI, scikit-learn TF-IDF char n-gram | Rerank riêng cho product search |
| Agent | `AgenticOrchestrator`, `ToolRegistry` | ReAct loop, function calling, orchestration |
| Evaluation | RAGAS, LangSmith, HuggingFace local embeddings | Offline/manual benchmark |
| Container | Docker Compose | Chạy Nginx, app, DB, Redis, Qdrant, rag-ml, reranker |

## Kiến Trúc Hệ Thống

```text
Browser
  -> Nginx API Gateway
      -> PHP App /api/chatbot
          -> AgenticOrchestrator
              -> LLM reasoning + tool calling
              -> ToolRegistry
                  -> retrieve_knowledge
                      -> KnowledgeRetriever
                      -> rag-ml /embed
                      -> Qdrant vector search
                      -> local lexical search Markdown + DB
                      -> rag-ml /rerank
                  -> search_products
                  -> get_product_detail
                  -> suggest_size
                  -> get_order_status
              -> AgentEvaluator
              -> ChatbotMemory
          -> MariaDB + Redis/file cache
      -> JSON response
```

## Docker Services

| Service | Vai trò | Port |
|---|---|---:|
| `nginx` | API Gateway, reverse proxy, rate limit | `8090:80` |
| `app` | PHP 8.2 Apache app | internal `80` |
| `db` | MariaDB shop database | `3308:3306` |
| `redis` | Cache | `6379` |
| `qdrant` | VectorDB cho RAG | internal `6333` |
| `rag-ml` | Embedding + cross-encoder rerank cho knowledge RAG | internal `8000` |
| `reranker` | TF-IDF sidecar cho product search | `8001:8000` |
| `phpmyadmin` | DB admin profile `tools` | `8091:80` |

## Luồng Kỹ Thuật Chi Tiết

### 1. Chatbot Request Flow

```text
User gửi câu hỏi
  -> Nginx kiểm rate limit
  -> PHP /api/chatbot nhận message + session_token
  -> AgenticOrchestrator nạp chat history + memory
  -> deterministic preflight cho guardrail, product_id, order auth
  -> LLM chọn tool nếu cần
  -> ToolRegistry execute tool
  -> Observation được đưa lại cho LLM
  -> LLM sinh draft answer
  -> AgentEvaluator kiểm hard constraints
  -> lưu chat_messages + tool_executions
  -> trả JSON response cho frontend
```

### 2. Knowledge RAG Flow

```text
Policy/CSKH query
  -> query rewriting theo nhóm return/shipping/payment/warranty/size
  -> embed query bằng Vietnamese bi-encoder
  -> Qdrant vector search top 20
  -> lexical keyword search Markdown + DB top 20
  -> merge candidate theo doc_key
  -> hybrid_score = 0.65 * vector_score + 0.35 * lexical_score
  -> cross-encoder rerank bằng PhoRanker
  -> chọn top 5 chunks
  -> trả context kèm source/category/score/retrieval_mode
```

Nguồn tri thức RAG:

| Nguồn | Nội dung |
|---|---|
| `knowledge/policies.md` | Đổi trả, hoàn tiền, vận chuyển, bảo hành, thanh toán |
| `knowledge/faq.md` | FAQ chính sách |
| `knowledge/shop-info.md` | Thông tin shop |
| `knowledge/size-guide.md` | Hướng dẫn size |
| Database `faqs`, `size_guides` | FAQ và bảng size trong DB |

Fallback RAG:

| Điều kiện | Cơ chế |
|---|---|
| Qdrant/rag-ml lỗi | Local lexical fallback trên Markdown + DB |
| Reranker lỗi | Hybrid result không rerank, metadata `hybrid_no_rerank` |
| Không có context | Chatbot trả lời chưa đủ dữ liệu shop, không tự bịa chính sách |

### 3. Product Basic Flow

```text
Product query
  -> intent product/search/detail/size
  -> search_products hoặc get_product_detail
  -> đọc Product API/DB
  -> trả product cards trong field products
  -> message ngắn hướng dẫn bấm thẻ sản phẩm
```

Các tool sản phẩm:

| Tool | Chức năng |
|---|---|
| `search_products` | Tìm sản phẩm theo keyword, category, khoảng giá |
| `get_product_detail` | Lấy đúng một sản phẩm theo `product_id` |
| `suggest_size` | Tư vấn size theo chiều cao/cân nặng và bảng size |
| `get_order_status` | Tra đơn hàng thuộc user đã đăng nhập |

### 4. Product Detail Routing

```text
"chi tiết sản phẩm mã 52"
  -> nhận diện product_id = 52
  -> gọi get_product_detail(product_id=52)
  -> trả đúng 1 product card
  -> message tóm tắt tên, giá, tồn kho, size, mô tả
```

Safety net: nếu LLM gọi nhầm `search_products(search="52")`, `ToolRegistry` tự route sang `get_product_detail`.

### 5. Evaluator/Self-Reflection

| Task | Rule kiểm tra chính |
|---|---|
| Product search | Không sai category, price, stock, product id |
| Product detail | Đúng product id, đúng giá/tồn kho/size, không trả nhiều sản phẩm |
| Size advice | Cần chiều cao/cân nặng, không khẳng định tuyệt đối |
| Order status | Bắt buộc auth, không lộ số điện thoại/địa chỉ |

Evaluator có thể chọn `return`, `revise_answer`, `retry_tool`, `ask_user`, `fallback` hoặc `deny`.

## API Response Chính

```json
{
  "message": "Áo Khoác Bomber Kaki Đen (mã 52)\nGiá: 550.000đ\nTình trạng: còn 12 sản phẩm\nSize: S, M, L, XL",
  "products": [
    {
      "id": 52,
      "name": "Áo Khoác Bomber Kaki Đen",
      "price": 550000,
      "stock": 12,
      "stock_status": "in_stock",
      "available_sizes": ["S", "M", "L", "XL"]
    }
  ],
  "knowledge_sources": [],
  "session_token": "...",
  "session_id": 1
}
```

## Metrics Đã Đo

Môi trường đo: Docker Compose local qua Nginx `http://localhost:8090`. Bộ benchmark gồm `25` positive/non-refusal turns, phủ RAG policy, product search/detail, size, mixed product+policy và order/CSKH chưa đăng nhập.

### Functional & Latency

| Nhóm | Metric | Giá trị |
|---|---|---:|
| Deterministic eval | Total turns | `25` |
| Deterministic eval | Passed | `25/25` |
| Deterministic eval | Failed | `0/25` |
| Latency | Min | `218 ms` |
| Latency | Avg | `1658.08 ms` |
| Latency | P50 | `1273 ms` |
| Latency | P95 | `4608 ms` |
| Latency | Max | `6239 ms` |

### RAGAS & LangSmith

| Nhóm | Metric | Giá trị |
|---|---|---:|
| RAGAS | Faithfulness | `0.6647` |
| RAGAS | Answer relevancy | `~0.6523` |
| RAGAS | Context precision | `0.9333` |
| RAGAS | Context recall | `0.8500` |
| LangSmith | Trace project | `fashion-shop-chatbot-positive-eval-after-routing-fix` |
| Eval setup | Embedding evaluator | `bkai-foundation-models/vietnamese-bi-encoder` local |

Ghi chú: RAGAS/LangSmith là offline/manual eval, không chạy bắt buộc trong CI public và không nằm trong Docker runtime.

### Docker Image Size

| Image | Size |
|---|---:|
| `shop_quan_ao-app:latest` | `723 MB` |
| `shop_quan_ao-rag-ml:latest` | `1.88 GB` |
| `shop_quan_ao-reranker:latest` | `603 MB` |
| `nginx:1.27-alpine` | `74.5 MB` |
| `mariadb:10.11` | `458 MB` |
| `redis:7-alpine` | `57.8 MB` |
| `qdrant/qdrant:v1.12.4` | `279 MB` |

`rag-ml` lớn do PyTorch CPU + SentenceTransformers + Transformers. Service đã bật `RAG_ML_WARMUP_ON_START=true`; health gần nhất xác nhận `embedding_model_loaded=true`, `reranker_model_loaded=true`, `warmup_elapsed_ms=4998`.

## Ignore Policy

Không commit các artefact runtime/eval:

| Nhóm | Pattern |
|---|---|
| Env/secret | `.env`, `.env.*`, trừ `.env.example` |
| Reports/evidence | `reports/`, `report/`, `*_report.*`, `*report*.json`, `BAO_CAO*`, `chatbot_*_eval*.json`, `ragas_*.json`, `langsmith_*.json` |
| Model/cache | `.cache/`, `.huggingface/`, `hf_cache/`, `models/`, `__pycache__/` |
| DB/dump/archive | `*.db`, `*.sqlite`, `*.sql.gz`, `*.dump`, `*.tar.gz`, `*.zip` |

