# Plan: Nâng cấp Chatbot Rule-based → Agentic RAG

## Technical Context

### Current Architecture
- Web PHP 8.2 + MariaDB 10.11, chạy Docker
- REST API tại `/api/*` — PHP thuần, routing bằng pattern matching
- Chatbot hiện tại: keyword matching (engine.php) → direct DB query → text
- Frontend: chat widget trong `includes/chatbox.php` (JS fetch tới `/api/chatbot`)
- DB: đã có `faqs`, `size_guides`, `outfit_suggestions`, `chat_sessions`, `chat_messages`

### Target Architecture
```
User → Chat widget (JS) → POST /api/chatbot
  └→ LLM Orchestrator (PHP):
       1. System prompt + tool definitions → gọi LLM API
       2. LLM trả về tool_call hoặc text
       3. Nếu tool_call: execute tool (gọi API internal) → gửi kết quả lại LLM
       4. LLM tổng hợp → response text
     ↕ fallback nếu LLM không available
     └→ Rule-based engine (hiện tại)
```

### Tool Definitions (LLM function calling)
Mỗi tool mapping tới 1 API endpoint hiện có hoặc cần tạo mới.

| Tool | API Endpoint | Status | Auth |
|---|---|---|---|
| `search_products` | `GET /api/products?search=&category=&min_price=&max_price=&sort=` | ✅ có | No |
| `get_product_detail` | `GET /api/products/{id}` | ✅ có | No |
| `get_product_reviews` | `GET /api/products/{id}/reviews` | ✅ có | No |
| `suggest_size` | `GET /api/size-guide?height=&weight=&category=` | ❌ cần tạo | No |
| `get_faq` | `GET /api/faq?category=` | ❌ cần tạo | No |
| `get_outfit` | `GET /api/outfit?product_id=&product_name=` | ❌ cần tạo | No |
| `get_order_status` | `GET /api/orders/{id}` | ✅ có | Bearer |
| `get_my_orders` | `GET /api/orders?status=` | ✅ có | Bearer |
| `get_cart` | `GET /api/cart` | ✅ có | Bearer |
| `get_categories` | `GET /api/categories` | ✅ có | No |

### LLM Provider — NEEDS CLARIFICATION
- User sẽ cấp API key sau (OpenAI / Claude / Gemini)
- Cần thiết kế abstraction layer: `LLMProviderInterface` → các implementation
- Mặc định fallback về rule-based engine hiện tại

### Knowledge Base — NEEDS CLARIFICATION
- Đã có 5 file .md trong `knowledge/`
- Có cần vector embedding + similarity search không?
- Hay chỉ dùng keyword search + tool call?

### Streaming — NEEDS CLARIFICATION
- Hiện tại chat widget dùng fetch → chờ response → hiện text
- Có cần streaming (SSE) cho LLM response không?

## Constitution Check

### Gates
1. **Không hardcode API key trong code** — dùng env var hoặc config file
2. **Fallback luôn hoạt động** — khi LLM API fail, chatbot vẫn chạy được (rule-based)
3. **Không lộ internal DB schema** — tool gọi API endpoints, không query DB trực tiếp từ tool code
4. **Session bảo mật** — chat_sessions dùng token riêng, không lộ user token

## Implementation Plan

### Phase 0: Research
- Resolve LLM provider: abstraction pattern
- Resolve KB strategy: embedding vs keyword
- Resolve streaming: SSE vs polling

### Phase 1: Design & Contracts
- data-model.md: chat_tool_calls table (log tool usage)
- contracts/: API contracts cho 3 endpoints mới
- quickstart.md: test scenarios

### Phase 2: Implementation
1. Tạo 3 API endpoints mới: `/api/size-guide`, `/api/faq`, `/api/outfit`
2. Tạo `LLMProviderInterface` + `OpenAIProvider` + `GeminiProvider` + `ClaudeProvider`
3. Tạo `ToolRegistry` class (định nghĩa tool, execute tool)
4. Tạo `AgenticOrchestrator` class (LLM call → parse tool_call → execute → tổng hợp)
5. Sửa `chatbot/index.php` để dùng orchestrator với fallback
6. Cập nhật tool definitions document
7. Cập nhật frontend widget (streaming nếu có)

### Files affected
- `api/index.php` — thêm routes mới
- `api/controllers/chatbot/*` — sửa orchestrator
- `api/controllers/chatbot/llm/` — mới: providers
- `api/controllers/chatbot/tools/` — mới: tool definitions
- `api/controllers/size-guide/` — mới
- `api/controllers/faq/` — mới
- `api/controllers/outfit/` — mới
- `includes/chatbox.php` — cập nhật widget
- `includes/footer.php` — không đổi
- `config/db.php` — không đổi
- `.env` — mới: LLM config
