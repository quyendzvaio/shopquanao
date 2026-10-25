# Đặc Tả Kỹ Thuật Chatbot

Phiên bản: 3.2
Cập nhật: 2026-10-21

## 1. Bản Chất Kiến Trúc

Chatbot là hệ thống deterministic-first hybrid, không phải ReAct agent. PHP (harness trong `src/harness/`) chịu trách nhiệm parse intent, phát hiện conflict, chọn tool, kiểm plan, kiểm evidence và sinh câu trả lời. LLM là dependency tùy chọn chỉ được bổ sung các entity mô tả chưa giải quyết dưới dạng JSON.

```text
Browser fetch POST /api/chatbot/stream (Server-Sent Events)
  -> Nginx (rate limit, no proxy buffering)
  -> src/app/api/controllers/Chatbot  (ChatStreamProtocol: origin + max-length guard, SSE framing)
  -> ChatbotService (src/agent, facade) -> TurnRunner::run()   [AgentFactory đọc env/config, ghép harness + LLM]
      1. loadMemory: ChatbotMemory::rememberUserMessage()
      2. beforeTurn (MiddlewareStack): CheckoutGuardrail (required) -> nếu match CheckoutDetector thì
         trả lời từ chối unsupported_checkout, bỏ qua bước 3-4
      3. IntentResolver
          -> DeterministicIntentParser
          -> ConflictDetector + ConflictResolver
          -> SemanticEntityEnricher (optional)
          -> MergeEngine
      4. ToolPlanner + PlanValidator -> EvidenceExecutionLoop
          -> ParallelToolExecutor -> ChatbotToolGateway (McpToolGateway | ToolRegistry) -> ToolApplicationService
          -> EvidenceNormalizer
          -> ProductConstraintVerifier
          -> ObservationEvaluator
          -> LightweightEvidenceScorer
          -> EvidenceDecisionRouter
      5. ResponseGenerator (grounded draft)
         -> chỉ lượt stream, không ở template mode:
            StreamingResponseGenerator -> MiddlewareModel -> native LLM token stream
            (AnswerTemplateFallback, required: mọi lỗi LLM -> phát grounded draft,
             latency.streaming = template_fallback)
         -> respond() (không stream) và CHATBOT_TEMPLATE_RESPONSE=1: dùng nguyên grounded draft
      6. afterTurn: FreeShippingNudge (optional, UC-C) -> TraceOutbox (optional, Langfuse)
      7. persist messages, routing metadata, tool executions; refreshSummary
  -> streamed answer + private grounded cards
  -> chat.delta / chat.cards / chat.complete events
```

Các bước 1-7 do `TurnRunner` điều phối; thứ tự và việc bật/tắt middleware nằm trong `config/harness.php`
(`CheckoutGuardrail` và `AnswerTemplateFallback` là `required`; nếu thiếu, `AgentFactory` ném `LogicException`
khi dựng middleware stack / turn runner cho một lượt chat).
Nhánh `unsupported_checkout` trong pipeline vẫn còn như lớp phòng thủ thứ hai khi LLM parser suy ra intent này.
Sơ đồ component, bảng phụ thuộc và cách thêm middleware/intent: [`src/harness/README.md`](../src/harness/README.md).
Harness dùng `service/` cho nghiệp vụ; mọi SQL của chatbot (memory, session, hội thoại, outfit catalog) nằm trong `src/repository/`, và env chỉ đọc ở `src/agent/` và `src/core/config/`; `ArchitectureTest` cưỡng chế, không có allowlist.

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
| `build_outfit` (UC-A) | `build_outfit`: `OutfitBuilder` ghép top+bottom hoặc dress (+ phụ kiện) theo `products.style_tags`, ngân sách, size. Xếp hạng: khi điểm bằng nhau, CÓ ngân sách thì set tổng cao hơn thắng (tận dụng ngân sách), KHÔNG có ngân sách thì tổng thấp hơn thắng; phụ kiện hòa điểm theo cùng quy tắc, sau đó id nhỏ hơn. Cố ý khác chữ "tổng thấp hơn" trong spec §5.4 gốc. "có <số tiền><đơn vị>" chỉ là ngân sách trong ngữ cảnh phối đồ; "ngân sách/budget <số tiền>" là ngân sách ở mọi ngữ cảnh |
| `freeship_gap` (UC-C) | `suggest_freeship_items` (`FreeShippingGapAdvisor`): hàng còn tồn có phần vượt ngưỡng `FREE_SHIPPING_THRESHOLD` (500000) nhỏ nhất; `FreeShippingNudge` chủ động nhắc khi thiếu ≤ `FREESHIP_NUDGE_MAX_GAP` (200000), một lần cho mỗi trạng thái giỏ |
| `unsupported_checkout` | Không gọi tool, trả guardrail cố định |

`PlanValidator` kiểm tool và arguments có đúng capability contract trước khi thực thi. `ToolRegistry` dùng PDO/allowlist; không có LLM-generated SQL.

## 4. Evidence Và Constraint

Sau tool execution, dữ liệu được chuẩn hóa thành `cards`, `knowledge_sources` và `evidence`. `ProductConstraintVerifier` loại card không thỏa đồng thời các entity trong intent, gồm loại sản phẩm, keyword, màu, size, khoảng giá, tồn kho, material, style, occasion và avoid khi có.

`EvidenceExecutionLoop` có tối đa ba vòng và chỉ lặp theo luật PHP: retry lỗi tool tạm thời, rewrite query policy/search một lần, hoặc gọi tool còn thiếu cho mixed intent. Đây là execution retry loop, không phải model reasoning loop.

## 5. Memory

`ChatbotMemory` lưu session summary và slots bằng rule extraction. Long-term memory chỉ áp dụng cho user đăng nhập. Summary hiện được tạo deterministic từ slots; production không gọi LLM để tóm tắt hội thoại.

Memory có thể bổ sung ngữ cảnh hội thoại như `last_product_id`, nhưng không được biến một policy turn độc lập thành product request.

## 6. Response Contract

Frontend chat turns use the same-origin endpoint `POST /api/chatbot/stream`,
which answers with Server-Sent Events (`Content-Type: text/event-stream`). The
browser reads it with `fetch` + `ReadableStream` because `EventSource` cannot
POST. PHP is the sole owner of identity resolution, persistence, intent/tool
selection, outfit building (UC-A), free-shipping advice (UC-C) and Product
Search. The final answer is emitted by the configured LLM's native token stream
after the grounded pipeline completes.

Client request (a logged-in user also sends `Authorization: Bearer <token>`):

```json
{
  "message": "Áo này phối với gì?",
  "session_token": "optional-guest-or-user-session-token"
}
```

Browser boundary (`ChatStreamProtocol`, shared with `POST /api/chatbot`):
a request carrying an `Origin` header must match the request host, unless
`CHATBOT_ALLOWED_ORIGINS` lists the allowed origins (403 otherwise); messages
longer than `CHATBOT_MAX_MESSAGE_CHARS` (default 2000) get 413. Nginx rate
limits both endpoints (`chatbot_limit`, 30 requests/minute per IP).

Server events, in order. Each frame is `event: <type>` + `data: <json>`, and the
JSON repeats `type`:

| Event | Meaning |
|---|---|
| `chat.progress` | PHP pipeline is processing |
| `chat.delta` | incremental safe text fragment |
| `chat.cards` | validated private shop cards, if any |
| `chat.complete` | final session/trace metadata |
| `chat.error` | safe terminal error instead of a response |

Text streaming is native provider output: each `chat.delta` is flushed as soon
as PHP receives it. If the LLM fails or returns nothing, the grounded draft is
emitted instead, so an LLM outage does not fail the turn. In template mode
(`CHATBOT_TEMPLATE_RESPONSE=1`) no LLM is called: the grounded draft is sent
unchanged as one `chat.delta`. `POST /api/chatbot` (non-streamed `respond()`)
always answers with the grounded draft as is. Product cards are
grounded by Product Search, reduced to `ChatStreamProtocol::CARD_FIELDS`, and
can only contain private shop `id` values. Only numeric/boolean latency fields
leave PHP. The model prompt is constrained to rewrite the grounded draft and
cannot create product cards.

Widget fallback: when the stream fails before any text arrived (network error,
5xx, `chat.error`, or a stream that ends early), the widget sends the same
message once to `POST /api/chatbot` and renders that JSON answer. Once text has
been shown it never replays the request, which avoids duplicate answers.

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

- `ChatbotToolManifestTest` — manifest là single source of truth, guardrail/mutation invariants.
- `TurnTierGateTest` — L0 (0 LLM call) vs L1 (LLM chỉ trám unresolved spans); safety intents never reach LLM.
- `ColorGuardTest` — bare `tim` ≠ `tím`; `cổ tim` không match `purple`.
- `ConflictGateTest` — hai giá trị cùng scope mâu thuẫn phải hỏi lại; shipping-scope không conflict với product budget.
- `ManifestPlannerTest` — mọi tool do `ToolPlanner` chọn đều nằm trong manifest (`manifest_tool_not_allowed`).
- `ChatbotGuardrailTest` — thêm giỏ/checkout → `unsupported_checkout` với 0 tool.
- `InputParserGateTest` — confident search/detail bỏ qua full LLM re-parse.
- `ResponseGeneratorGroundingTest` + `RefusalEchoTest` — answer bám evidence (giá/kho/tổng tiền) và quote lại câu hỏi gốc ở refusal.
- `CatalogColorSearchTest` (integration, DB thật) — `tìm áo màu đen` ra 52/63, `tím` loại `Cổ Tim`.

## 8. Mã Legacy Đã Loại Bỏ

- `AgenticOrchestrator.php`: chứa production pipeline lẫn nhánh LLM cũ không thể chạy tới.
- `engine.php`: fallback engine không còn nằm trong production call graph.
- `evaluator/AgentEvaluator.php`: chỉ được test legacy sử dụng.
- `SYSTEM_PROMPT` và `processWithLLM()`: dead code thuộc nhánh unreachable.
- `ThoughtStateBuilder.php`: data mapper đã được merge vào execution loop.
- `ReasoningLoop.php`: đổi thành `EvidenceExecutionLoop.php` để phản ánh đúng retry/evidence behavior.
- `LLMSemanticCompletion.php`: đổi thành `SemanticEntityEnricher.php` để phản ánh đúng vai trò.
