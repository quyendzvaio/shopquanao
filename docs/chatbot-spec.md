# Đặc Tả Kỹ Thuật Chatbot

Phiên bản: 3.1
Cập nhật: 2026-08-31

## 1. Bản Chất Kiến Trúc

Chatbot là hệ thống deterministic-first hybrid, không phải ReAct agent. PHP chịu trách nhiệm parse intent, phát hiện conflict, chọn tool, kiểm plan, kiểm evidence và sinh câu trả lời. LLM là dependency tùy chọn chỉ được bổ sung các entity mô tả chưa giải quyết dưới dạng JSON.

```text
Browser WebSocket /ws/chatbot
  -> chat-stream gateway (Node)
      -> POST /api/chatbot/stream (internal Compose network, NDJSON)
  -> ChatbotService
      -> ChatbotMemory::rememberUserMessage()
      -> IntentResolver
          -> DeterministicIntentParser
          -> ConflictDetector + ConflictResolver
          -> SemanticEntityEnricher (optional)
          -> MergeEngine
      -> ToolPlanner + PlanValidator
      -> EvidenceExecutionLoop
          -> ParallelToolExecutor -> ToolRegistry
          -> EvidenceNormalizer
          -> ProductConstraintVerifier
          -> ObservationEvaluator
          -> LightweightEvidenceScorer
          -> EvidenceDecisionRouter
      -> ResponseGenerator (grounded draft)
      -> StreamingResponseGenerator -> native LLM token stream
      -> persist messages, routing metadata and tool executions
  -> streamed answer + private grounded cards
  -> chat.delta / chat.cards / chat.complete events
```

## 2. Ranh Giới LLM

`SemanticEntityEnricher` chỉ chạy khi parser tạo `unresolved_spans` có `affects_execution=true` và danh sách `expected_fields`. Prompt không cung cấp tool definitions cho function calling, lời gọi dùng `tools=[]` và `toolChoice=none`.

LLM được phép đề xuất các field nằm trong allowlist của span, ví dụ `style`, `occasion`, `avoid`. LLM không được:

- Chọn hoặc thay đổi `primary_intent`.
- Chọn tool, capability hay thứ tự gọi tool.
- Tạo SQL.
- Ghi đè field deterministic đã khóa.
- Resolve conflict mà rule engine đánh dấu cần hỏi lại user.

Nếu LLM không được cấu hình, timeout hoặc trả JSON lỗi, pipeline tiếp tục với các field deterministic hiện có.

## 3. Intent Và Tool Mapping

| Intent | Tool do `ToolPlanner` chọn |
|---|---|
| `product_search` | `search_products` |
| `product_detail` | `get_product_detail` |
| `size_advice` | `suggest_size` khi đủ chiều cao/cân nặng |
| `return_exchange`, `shipping`, `policy` | `retrieve_knowledge` |
| `mixed_product_policy` | Tool sản phẩm và `retrieve_knowledge` |
| `order_status` | `get_order_status` |
| `unsupported_outfit`, `unsupported_checkout` | Không gọi tool, trả guardrail cố định |

`PlanValidator` kiểm tool và arguments có đúng capability contract trước khi thực thi. `ToolRegistry` dùng PDO/allowlist; không có LLM-generated SQL.

## 4. Evidence Và Constraint

Sau tool execution, dữ liệu được chuẩn hóa thành `cards`, `knowledge_sources` và `evidence`. `ProductConstraintVerifier` loại card không thỏa đồng thời các entity trong intent, gồm loại sản phẩm, keyword, màu, size, khoảng giá, tồn kho, material, style, occasion và avoid khi có.

`EvidenceExecutionLoop` có tối đa ba vòng và chỉ lặp theo luật PHP: retry lỗi tool tạm thời, rewrite query policy/search một lần, hoặc gọi tool còn thiếu cho mixed intent. Đây là execution retry loop, không phải model reasoning loop.

## 5. Memory

`ChatbotMemory` lưu session summary và slots bằng rule extraction. Long-term memory chỉ áp dụng cho user đăng nhập. Summary hiện được tạo deterministic từ slots; production không gọi LLM để tóm tắt hội thoại.

Memory có thể bổ sung ngữ cảnh hội thoại như `last_product_id`, nhưng không được biến một policy turn độc lập thành product request.

## 6. Response Contract

Frontend chat turns use the same-origin WebSocket endpoint `GET /ws/chatbot`
(HTTP upgrade). The gateway accepts exactly one in-flight `chat.send` request
per socket and forwards it to the PHP streaming endpoint. PHP remains the sole
owner of identity resolution, persistence, intent/tool selection, Glance
styling, and Product Search. The final answer is emitted by the configured
LLM's native token stream after the grounded pipeline completes.

Client request:

```json
{
  "type": "chat.send",
  "request_id": "browser-generated-id",
  "message": "Áo này phối với gì?",
  "session_token": "optional-guest-or-user-session-token",
  "authorization": "Bearer optional-user-token"
}
```

The browser WebSocket API cannot set an `Authorization` header. For a logged-in
user, the existing bearer token is therefore sent only inside the same-origin
TLS WebSocket frame; the gateway forwards it as an HTTP header and never logs,
persists, or sends it back. Origin must match the public forwarded
host/protocol, unless `CHAT_STREAM_ALLOWED_ORIGINS` is explicitly configured.

Server events, in order:

| Event | Meaning |
|---|---|
| `chat.started` | request accepted |
| `chat.progress` | PHP pipeline is processing |
| `chat.delta` | incremental safe text fragment |
| `chat.cards` | validated private shop cards, if any |
| `chat.complete` | final session/trace metadata |
| `chat.error` | safe terminal error instead of a response |

Text streaming is native provider output: each `chat.delta` is forwarded as
soon as the PHP streaming endpoint receives it. Product cards are still
grounded by Product Search, allow-listed at the gateway, and can only contain
private shop `id` values; `provider_*` identifiers are removed. The model prompt
is constrained to rewrite the grounded draft and cannot create product cards.
The client never auto-replays a request after a disconnected socket, preventing
duplicate cart/event side effects.

`POST /api/chatbot` remains available for internal integration and controlled
compatibility clients, but the storefront widget uses WebSocket delivery.

Public endpoint giữ contract:

```json
{
  "message": "...",
  "answer": "...",
  "products": [],
  "cards": [],
  "knowledge_sources": [],
  "primary_intent": "product_search",
  "response_type": "final_answer",
  "requested_fields": [],
  "missing_slots": [],
  "trace_id": "...",
  "latency": {}
}
```

Routing metadata ghi rõ `tool_selection_mode=deterministic_php`, tool được chọn, entity đã merge và việc LLM enrichment có được dùng hay không. Trace nội bộ dùng tên `execution_trace`, không mô tả chain-of-thought.

## 7. Characterization Tests

`tests/Unit/ProductionPipelineTest.php` xác nhận:

- Query deterministic chọn đúng tool khi không có LLM.
- Bật LLM entity enrichment không làm đổi tool đã chọn.
- LLM được gọi với `tools=[]`, `toolChoice=none`.
- `thêm áo mã 52 vào giỏ` route thành `unsupported_checkout`, không gọi LLM và không gọi product tool.
- LLM không ghi đè giá, màu hoặc product type đã khóa.

`tests/Integration/ChatbotAPITest.php` xác nhận production service, persistence, routing metadata, product constraints và session continuity.

## 8. Mã Legacy Đã Loại Bỏ

- `AgenticOrchestrator.php`: chứa production pipeline lẫn nhánh LLM cũ không thể chạy tới.
- `engine.php`: fallback engine không còn nằm trong production call graph.
- `evaluator/AgentEvaluator.php`: chỉ được test legacy sử dụng.
- `SYSTEM_PROMPT` và `processWithLLM()`: dead code thuộc nhánh unreachable.
- `ThoughtStateBuilder.php`: data mapper đã được merge vào execution loop.
- `ReasoningLoop.php`: đổi thành `EvidenceExecutionLoop.php` để phản ánh đúng retry/evidence behavior.
- `LLMSemanticCompletion.php`: đổi thành `SemanticEntityEnricher.php` để phản ánh đúng vai trò.
