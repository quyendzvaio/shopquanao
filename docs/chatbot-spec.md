# Đặc Tả Kỹ Thuật Chatbot

Phiên bản: 3.0
Cập nhật: 2026-08-01

## 1. Bản Chất Kiến Trúc

Chatbot là hệ thống deterministic-first hybrid, không phải ReAct agent. PHP chịu trách nhiệm parse intent, phát hiện conflict, chọn tool, kiểm plan, kiểm evidence và sinh câu trả lời. LLM là dependency tùy chọn chỉ được bổ sung các entity mô tả chưa giải quyết dưới dạng JSON.

```text
POST /api/chatbot
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
      -> ResponseGenerator
      -> OnlineValidator
      -> persist messages, routing metadata and tool executions
  -> JSON response
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
