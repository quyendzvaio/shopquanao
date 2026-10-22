# Spec: Tách harness chatbot thành các component (agent = harness + LLM)
- Ngày: 2026-10-22
- Trạng thái: CHỜ DUYỆT
- Nhánh: `refactor/harness-components` (tách từ `develop`)
- Phạm vi: chỉ phần chatbot. Phần website (`public/`, `src/Shop`) là dự án sau, có spec riêng.

## 1. Bối cảnh và lý do

Đọc code chatbot hiện tại khó hiểu, và khó tái sử dụng vì logic bám chặt vào nhau. Kết quả rà soát ngày 2026-10-22:

- **Không có phụ thuộc vòng giữa các module.** Chiều phụ thuộc vẫn đúng: `Http → Chatbot → Shop → Infrastructure`. Khung kiến trúc giữ được.
- **`ChatbotService` ôm hết mọi việc.** Class này:
  - phụ thuộc 22 class và tự `new` ở 17 chỗ;
  - tự đọc env để chọn LLM và transport;
  - có `runPipeline` dài 121 dòng;
  - tự gọi nudge free ship, ghi log và ghi trace Langfuse.
  Hệ quả: muốn tắt nudge hay đổi LLM đều phải sửa class này.
- **`src/Chatbot/Pipeline/` là một thư mục phẳng 23 class, khoảng 4.200 dòng.** Bên trong trộn lẫn nhận diện intent, lập plan, chấm bằng chứng và sinh câu trả lời. Người đọc không thấy agent gồm những bộ phận nào.
- **LLM nằm lẫn trong harness.** `LLMFactory` bị gọi thẳng từ `ChatbotService`.
- **Khoảng 10 class tự đọc `getenv()`.** Không có một nơi duy nhất cho biết agent dùng những cấu hình nào.
- **Có các hàm quá dài:**
  - `EvidenceExecutionLoop::run`: 116 dòng
  - `PlanValidator::validateUseCase`: 86 dòng
  - `CapabilityRegistry::knownMetadata`: 99 dòng
  - `DeepSeekProvider::chat` (100 dòng) và `chatStream` (110 dòng) lặp phần lớn logic của nhau.

**Triết lý được chọn:** agent = harness + LLM, theo tinh thần bài viết "How to Build a Custom Agent Harness" của LangChain.
- LLM là một bộ phận cắm vào qua một interface.
- Harness là phần khung đưa đúng context cho LLM ở từng bước.
- Middleware là cơ chế cắm-rút thật, không phải chỉ một thư mục gom class.

## 2. Mục tiêu, phi mục tiêu, phản mục tiêu

**Mục tiêu (tiêu chí "xong", do người dùng chốt):**
1. Người lạ mở `src/Harness/` là thấy ngay agent gồm những bộ phận nào.
2. Mỗi thư mục component có một interface rõ ràng ở ranh giới.
3. Thay một bộ phận không phải sửa bộ phận khác. Hai ví dụ chuẩn:
   - Đổi LLM provider: chỉ đụng `src/Llm/` và cấu hình.
   - Tắt nudge free ship: chỉ bỏ một dòng trong `config/harness.php`.
4. Hành vi bên ngoài giữ nguyên. Toàn bộ unit test (157 test, gồm 6 test fallback stream và 10 test protocol) và 40 eval case phải pass **mà không sửa expectation nào**.

**Phi mục tiêu:**
- Không chia theo use case. Người dùng chấp nhận đánh đổi: logic của một intent (ví dụ phối đồ) vẫn trải qua nhiều component (Intent nhận diện, Tools xử lý, Response viết câu trả lời).
- Không thêm tính năng mới, không đổi câu trả lời, không đổi API HTTP, không đổi schema DB.
- Không đổi `mcp-server/` (TypeScript), trừ khi bắt buộc.
- Không đụng phần website (`public/`, `src/Shop`, `src/Http/Controllers` ngoài 2 controller chatbot). Phần này thuộc spec sau.
- Không chuyển sang namespace PSR-4. Repo dùng Composer classmap, không namespace. Giữ nguyên.

**Phản mục tiêu (dù chạy được vẫn coi là thất bại):**
- Đổi tên hàng loạt class khiến diff không đọc nổi. Nguyên tắc: di chuyển file trước, đổi tên thật ít.
- Tạo interface hoặc lớp trừu tượng không có người dùng thật ("cho sau này"). Mỗi interface và mỗi điểm móc phải có ít nhất một nơi dùng ngay trong dự án này.
- Middleware làm được việc mà guardrail cứng phải chặn. Ví dụ: cấu hình tắt được bước kiểm tra thẻ sản phẩm phải có trong catalog.
- Một commit nào đó để test hoặc eval đỏ.

## 3. Cấu trúc đích

```
src/
  Agent/                     ← "agent = harness + LLM": nơi lắp ráp
    AgentFactory.php         ← nơi DUY NHẤT đọc env/config cho chatbot; dựng Harness, cắm LLM, gắn middleware
    ChatbotService.php       ← facade mỏng giữ nguyên chữ ký cũ (controller, eval, test vẫn gọi như trước)
  Llm/                       ← LLM nằm NGOÀI harness: chỉ các provider cụ thể
    DeepSeekProvider.php     (OpenAI-compatible; provider mới = thêm file ở đây)
  Harness/
    README.md                ← sơ đồ 1 lượt chat, bảng component (đọc file này trước)
    Model/        cổng cắm LLM: LLMProvider, StreamingLLMProvider (interface), LLMResponse, ToolCall,
                  LLMTransportException (tách file riêng), ModelCall, MiddlewareModel (decorator, mục 5)
    Turn/         TurnRunner (thay runPipeline), TurnContext, TurnResult
    Intent/       IntentResolver, DeterministicIntentParser, PartialParseResult, ConflictDetector,
                  ConflictResolver, MergeEngine, LlmInputParser, SemanticEntityEnricher, TurnTierGate,
                  CheckoutDetector (MỚI: regex isCheckout tách ra, dùng chung cho parser và guardrail)
    Planning/     ChatbotToolManifest, CapabilityRegistry, ToolPlanner, PlanValidator
    Loop/         EvidenceExecutionLoop, ParallelToolExecutor, NoProgressDetector, EvidenceDecisionRouter
    Evidence/     EvidenceNormalizer, ProductConstraintVerifier, ObservationEvaluator, LightweightEvidenceScorer
    Tools/        ChatbotToolGateway (interface), ToolDefinitionCatalog, ToolRegistry, McpToolGateway,
                  ShadowToolGateway, ToolApplicationService
      Outfit/       OutfitService, OutfitBuilder, OutfitCatalogRepository, OutfitItem, OutfitPalette,
                    OutfitRequest, OutfitResult, OutfitSet, OutfitSlot
      FreeShipping/ FreeShippingService, FreeShippingGapAdvisor, FreeShippingPolicy
    Knowledge/    KnowledgeRetriever, KnowledgeService (RAG)
    Response/     ResponseGenerator, StreamingResponseGenerator
    Memory/       ChatbotMemoryStore + ChatbotConversationStore (interface), ChatbotMemory,
                  PdoChatbotConversationStore, ChatbotSessionContext
    Middleware/   Middleware (interface), BaseMiddleware, MiddlewareStack, CheckoutGuardrail,
                  AnswerTemplateFallback, FreeShippingNudge, TraceOutbox
  Http/Support/ChatStreamProtocol.php   ← định dạng SSE + chặn origin/độ dài, là chuyện của HTTP
  Infrastructure/Observability/         ← giữ nguyên: LangfuseTraceOutbox + LangfuseOtlpTracePublisher
config/
  chatbot_tools.php          ← giữ nguyên (manifest intent → tool)
  harness.php                ← MỚI: danh sách middleware đang bật theo thứ tự + các cờ của harness
```

Ghi chú:
- `src/Chatbot/` biến mất sau khi refactor xong.
- `LangfuseTraceOutbox` và `LangfuseOtlpTracePublisher` ở lại `src/Infrastructure/Observability/`. Nếu chuyển outbox vào Harness, publisher (thuộc Infrastructure) sẽ phụ thuộc ngược vào Harness. Middleware `TraceOutbox` chỉ gọi outbox qua constructor injection.
- `Cache` ở lại `Infrastructure`, vì `Shop` cũng dùng nó.
- Test unit chuyển theo cấu trúc mới: `tests/Unit/Harness/<Component>/…`. PHPUnit đã quét thư mục con nên không cần sửa `phpunit.xml`.

## 4. Ranh giới component (interface công khai)

Mỗi component có một `README.md` ngắn (5–15 dòng) nêu 3 điều: nó làm gì, class nào là cửa vào, nó được phép dùng component nào.

Bảng này là **luật bắt buộc**: code phải được sửa cho khớp bảng, không làm ngược lại. Bảng dưới đây đã được đối chiếu với code thật, gồm cả những chỗ phụ thuộc mà thiết kế này buộc phải có.

| Component | Cửa vào công khai | Được dùng |
|---|---|---|
| Model | `LLMProvider`, `StreamingLLMProvider`, `LLMResponse`, `ModelCall`, `MiddlewareModel` | Middleware (chỉ interface `Middleware`, để `MiddlewareModel` gọi stack) |
| Intent | `IntentResolver::resolve()`, `CheckoutDetector` | Model, Planning |
| Planning | `ChatbotToolManifest`, `CapabilityRegistry`, `ToolPlanner`, `PlanValidator` | — |
| Loop | `EvidenceExecutionLoop::run()` | Planning, Tools, Evidence |
| Evidence | `EvidenceNormalizer`, `ProductConstraintVerifier`, `LightweightEvidenceScorer` | — |
| Tools | `ChatbotToolGateway`, `FreeShippingPolicy`, `FreeShippingService` | Knowledge |
| Knowledge | `KnowledgeService` | — |
| Response | `ResponseGenerator`, `StreamingResponseGenerator` | Model, Tools (chỉ đọc `FreeShippingPolicy` đã được inject; không tự đọc env) |
| Memory | `ChatbotMemoryStore`, `ChatbotConversationStore`, `ChatbotSessionContext` | — |
| Middleware | `Middleware`, `BaseMiddleware`, `MiddlewareStack` | Turn (chỉ kiểu dữ liệu `TurnContext`/`TurnResult`), Model, Intent (`CheckoutDetector`), Response (câu từ chối), Evidence (`productCards`), Tools (FreeShipping) |
| Turn | `TurnRunner::run()`, `TurnContext`, `TurnResult` | mọi component ở trên |

Về chiều Turn ↔ Middleware: `TurnContext` và `TurnResult` là kiểu dữ liệu thuần, không có logic, và không phụ thuộc gì. Middleware chỉ được dùng hai kiểu này, không được dùng `TurnRunner`. `ArchitectureTest` cho phép đúng ngoại lệ này. Model ↔ Middleware cũng xử lý tương tự: `MiddlewareModel` chỉ biết interface `Middleware`.

Quy tắc chung:
- Component được dùng `Shop/*` và `Infrastructure/*` (domain và hạ tầng).
- **Không** component nào trong `Harness/` được tham chiếu tới class trong `src/Llm/` hay `src/Agent/`.
- Không gọi `getenv()`, `$_ENV`, `$_SERVER` bên trong `src/Harness/`. Mọi giá trị cấu hình đi vào qua constructor, ví dụ `FreeShippingPolicy::fromEnvironment()` chỉ được gọi trong `AgentFactory`.
- Mỗi luật chỉ có **một nguồn**. Regex checkout nằm ở `CheckoutDetector`, câu từ chối là hằng số trong `ResponseGenerator`. Guardrail và lớp phòng thủ thứ hai trong pipeline cùng dùng hai nguồn này, không sao chép.

**Test kiến trúc** (`tests/Unit/Harness/ArchitectureTest.php`) quét mã nguồn để cưỡng chế ba quy tắc trên, cộng thêm bảng "Được dùng". Nhờ vậy ranh giới không bị xói mòn theo thời gian.

## 5. Model: cổng cắm LLM

- `Harness/Model/` chứa interface và DTO: `LLMProvider`, `StreamingLLMProvider`, `LLMResponse`, `ToolCall`, `LLMTransportException`, `ModelCall`. Harness không biết provider cụ thể là gì.
- `src/Llm/DeepSeekProvider` là một hiện thực của cổng này. Thêm provider mới nghĩa là thêm một class trong `src/Llm/` và một nhánh trong `AgentFactory`.
- **Cách middleware bọc model:** `AgentFactory` bọc provider thật trong `MiddlewareModel`. Đây là một decorator hiện thực cả `LLMProvider` lẫn `StreamingLLMProvider`, và chuyển mọi lời gọi qua `MiddlewareStack::wrapModelCall`. Các component (Intent, Response) vẫn chỉ thấy interface `LLMProvider`, không biết có middleware.
- **Thông tin đi kèm lời gọi:** người gọi đặt khóa dành riêng `$options['harness']` = `['purpose' => 'input_parse'|'entity_enrich'|'answer', 'grounded_draft' => ?string]`. `MiddlewareModel` tách khóa này ra để dựng `ModelCall`, rồi gỡ nó khỏi `options` trước khi gọi provider thật. Provider không bao giờ thấy khóa này.
- `ModelCall` mang theo: `purpose`, `messages`, `options` (đã gỡ khóa harness), `groundedDraft`, `onDelta` (null nếu không stream), `stream` (bool).
- **Trả kết quả fallback về:** `LLMResponse` có thêm một field `?string $fallbackReason` (mặc định `null`). `StreamingResponseGenerator` đọc field này để trả `['text', 'fallback']` như hiện nay. Nhờ vậy `latency.streaming=template_fallback` và log `chatbot_stream_llm` giữ nguyên.
- Tách phần HTTP/curl và phân loại lỗi dùng chung của `DeepSeekProvider::chat` và `chatStream` thành hàm riêng. Hai hàm công khai giữ nguyên hành vi.

## 6. Middleware: cơ chế cắm-rút

### 6.1 Interface

```php
interface Middleware
{
    /** Trả về TurnResult để chặn lượt chat ngay (không chạy pipeline), hoặc null để đi tiếp. */
    public function beforeTurn(TurnContext $turn): ?TurnResult;

    /** Bọc một lời gọi LLM. $next thực hiện lời gọi thật (hoặc middleware kế tiếp). */
    public function wrapModelCall(ModelCall $call, callable $next): LLMResponse;

    /** Nhận kết quả lượt chat, có thể bổ sung (ví dụ thêm câu gợi ý), trả về kết quả mới. */
    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult;
}
```

- Có một lớp nền `BaseMiddleware`, mặc định không làm gì ở cả 3 điểm móc. Mỗi middleware chỉ ghi đè điểm móc nó cần.
- **Chỉ có 3 điểm móc**, vì chỉ 3 điểm này có người dùng thật (bảng 6.2). Điểm móc `wrapToolCall` **không** làm trong dự án này: retry tool hiện thuộc vòng lặp bằng chứng (`EvidenceDecisionRouter` quyết định `retry_tool` trong ngân sách của loop), và việc chuyển nó ra ngoài sẽ đổi ngữ nghĩa.
- `MiddlewareStack` chạy theo thứ tự trong `config/harness.php`:
  - `beforeTurn`: chạy theo thứ tự, gặp middleware chặn đầu tiên thì dừng.
  - `wrapModelCall`: lồng nhau kiểu củ hành. Middleware đầu danh sách nằm ngoài cùng.
  - `afterTurn`: chạy theo thứ tự, mỗi middleware nhận kết quả của middleware trước.
- **Cô lập lỗi:** lỗi trong `afterTurn` của một middleware không tùy chọn bị bắt lại, ghi log `{operation: middleware_<name>, success: false}`, và lượt chat đi tiếp. Middleware `required` thì để lỗi lan ra. Đây là cách `freeShippingNudge()` đang làm, nay áp dụng chung.

### 6.2 Middleware chuyển từ code hiện có

| Middleware | Điểm móc | Lấy từ | Tắt được? |
|---|---|---|---|
| `CheckoutGuardrail` | `beforeTurn` | Regex `isCheckout` trong `DeterministicIntentParser` (ưu tiên số 1 trong `inferIntent`) + câu từ chối trong `ResponseGenerator` | **Không** (`required`) |
| `AnswerTemplateFallback` | `wrapModelCall` (`purpose=answer`) | Phần try/catch + `fallback()` trong `StreamingResponseGenerator` | **Không** (`required`: luật "lượt chat không bao giờ hỏng vì LLM") |
| `FreeShippingNudge` | `afterTurn` | `ChatbotService::freeShippingNudge()` + `withNudgeCard()` + class `FreeShippingNudge` hiện tại | Có |
| `TraceOutbox` | `afterTurn` (cuối danh sách) | `ChatbotService::enqueueLangfuseTrace()` | Có |

`config/harness.php` mặc định:

```php
return [
    'middleware' => [
        CheckoutGuardrail::class,      // required: AgentFactory từ chối khởi động nếu thiếu
        AnswerTemplateFallback::class, // required
        FreeShippingNudge::class,
        TraceOutbox::class,
    ],
];
```

### 6.3 Giữ nguyên hành vi

- **`CheckoutGuardrail`:**
  - Trả `TurnResult` có `primary_intent=unsupported_checkout`, `response_type` và câu trả lời giống hệt hiện nay, và không gọi tool nào. Đây là những gì 8 eval case nhóm `guardrail` kiểm tra.
  - Dùng `CheckoutDetector` trên đúng chuỗi đã chuẩn hoá mà `inferIntent` đang nhận (`$lower`).
  - Các nhánh `unsupported_checkout` trong pipeline **giữ nguyên**, làm lớp phòng thủ thứ hai cho trường hợp LLM input parser suy ra intent này mà regex không bắt.
- **Lượt chat bị chặn chỉ bỏ qua phần giữa.** "Chặn" nghĩa là bỏ qua intent, tool và evidence. Các bước còn lại vẫn chạy như một lượt bình thường, để lịch sử, memory và log không đổi so với hiện nay:
  - `beforeTurn` chạy **sau** bước nạp memory (`rememberUserMessage`), nên slot như sản phẩm vừa nhắc vẫn được cập nhật.
  - Câu từ chối đi qua bước sinh câu trả lời như mọi bản nháp grounded khác. Ở chế độ stream, LLM vẫn được viết lại câu này như hiện nay, và `AnswerTemplateFallback` vẫn bọc lời gọi đó.
  - Sau đó vẫn chạy các `afterTurn` (nudge tự bỏ qua vì đây không phải intent mua sắm), ghi trace, ghi log `routing_pipeline`, `saveMessages` và `refreshSummary`.
- **Ngoại lệ duy nhất về hành vi:** một câu vừa đòi checkout vừa có xung đột chưa giải được (ví dụ hai mức giá mâu thuẫn) hiện được hỏi lại để làm rõ, sau refactor sẽ bị từ chối luôn. Lý do: guardrail chạy trước bước resolve intent. Từ chối an toàn hơn hỏi lại, và trường hợp này không có trong eval. Đây là quyết định của tôi.
- **`AnswerTemplateFallback`:**
  - Giữ đúng 6 hành vi mà `StreamingResponseGenerator` đang có test.
  - Theo dõi các delta đã gửi.
  - Khi lỗi xảy ra trước token đầu tiên: gửi bản nháp.
  - Khi đã gửi một phần: nối `"\n\n" + bản nháp`.
  - Khi trả về rỗng: gửi bản nháp.
  - Khi không có bản nháp: ném lại lỗi.
  - `latency.streaming=template_fallback` và log `chatbot_stream_llm` giữ nguyên.
- **`FreeShippingNudge`:**
  - Chỉ chạy khi có user, đúng intent mua sắm, và mỗi fingerprint giỏ hàng một lần.
  - Ở chế độ stream, câu gợi ý được gửi thành **một delta riêng sau câu trả lời** (qua `TurnContext::emit`), để LLM rewrite không làm rơi mất nó.
  - Thẻ sản phẩm của nudge được gộp vào `products`/`cards` của kết quả, không trùng id.
- **Thứ tự cuối lượt** do `TurnRunner` giữ: pipeline → câu trả lời → các `afterTurn` → lưu hội thoại (`saveMessages`) → `refreshSummary`. Việc ghi trace chuyển lên trước `saveMessages`. Hai thao tác ghi DB này độc lập nhau, nên đây là quyết định của tôi, không ảnh hưởng hành vi.

## 7. Turn và AgentFactory

- **`TurnRunner::run(TurnContext): TurnResult`** thay cho `runPipeline` + `respond` + `respondStreaming`. Một hàm chạy cả hai chế độ: stream hay không chỉ khác ở chỗ `TurnContext` có `emit` hay không. Logic hiện đang lặp giữa `respond` và `respondStreaming` gộp lại một chỗ. Hàm chính đọc từ trên xuống như sơ đồ:
  1. middleware `beforeTurn`
  2. nạp memory
  3. resolve intent (hoặc trả lời làm rõ)
  4. vòng lặp bằng chứng
  5. sinh câu trả lời
  6. middleware `afterTurn`
  7. lưu lại

  Mỗi bước là một hàm private ngắn.
- **`TurnResult`** là một mảng có kiểu (array shape) giống kết quả hiện nay: `message`, `answer`, `products`, `cards`, `response_type`, `primary_intent`, `latency`, `trace_id`… Tôi không đổi sang object để controller và eval không phải sửa. Đây là quyết định của tôi. Một wrapper mỏng có thể thêm sau.
- **`AgentFactory::create(PDO, sessionId, userId, ?overrides)`:**
  - đọc env một lần thành `HarnessConfig`;
  - chọn transport tool (`internal`/`mcp`/`shadow`) và LLM (kể cả `CHATBOT_FORCE_L0`, `CHATBOT_TEMPLATE_RESPONSE`);
  - dựng `MiddlewareStack` từ `config/harness.php` và kiểm tra middleware `required`;
  - trả về agent đã lắp xong.
- Các class đang tự đọc env trong harness (`KnowledgeRetriever`, `McpToolGateway`, `FreeShippingPolicy`, `ResponseGenerator`, phần env của `ChatbotService`) nhận giá trị qua constructor. Giá trị mặc định giữ nguyên. `LangfuseTraceOutbox` ở `Infrastructure` nên vẫn tự đọc env được.
- **`ChatbotService` thành facade mỏng.** Constructor và `respond()`/`respondStreaming()` giữ chữ ký cũ, vì 2 controller và `eval/AgentEvalCaseRunner.php` đang gọi nó. Hiện chưa có unit test nào chạy trọn một lượt qua `ChatbotService`. Bên trong facade chỉ gọi `AgentFactory` rồi `TurnRunner`. Các tham số tùy chọn cũ (`$llm`, `$toolGateway`, `$memory`, `$conversationStore`, `$responseGenerator`) vẫn được nhận và chuyển thành overrides.
- **Test lắp ráp agent** (`AgentFactory`: bỏ nudge khỏi config thì không còn câu gợi ý; thiếu middleware `required` thì báo lỗi) là **integration test** trên `shop_test`, chạy trọn một lượt bằng tool transport `internal` và không dùng LLM, giống cách eval đang chạy.

## 8. Chiến lược thực hiện (an toàn từng bước)

Điều kiện tiên quyết: CI trên `develop` phải xanh trước khi bắt đầu, tức PR sửa thứ tự `composer install` trong job Integration (`fix/ci-integration-step-order`) đã merge. Lý do: từ PR #12 tới nay job Integration đỏ, và eval chưa từng chạy trên CI.

Các giai đoạn. Mỗi giai đoạn gồm một hoặc vài commit, và **sau mỗi commit** phải chạy toàn bộ unit test, PHPStan, integration, eval 40 case và MCP test:

1. **Chỉ di chuyển file.** `git mv` các class sang cấu trúc ở mục 3, kể cả test, rồi `composer dump-autoload`. Không sửa code, trừ docblock nhắc đường dẫn cũ. Classmap không quan tâm thư mục nên hành vi chắc chắn không đổi. Sửa các đường dẫn cứng tới file (ví dụ `config/chatbot_tools.php`, các `require`), nếu có.
2. **Tách LLM ra ngoài.** Chỉ `DeepSeekProvider` sang `src/Llm/`. Interface, DTO và exception sang `Harness/Model/`. Chuyển `LLMFactory` thành phần chọn provider trong `AgentFactory`. Tách lõi HTTP dùng chung của `DeepSeekProvider`.
3. **Cơ chế middleware.** Tạo `Middleware`, `BaseMiddleware`, `MiddlewareStack` và `config/harness.php`. Chuyển 4 middleware ở mục 6.2, mỗi middleware một commit, kèm unit test riêng cho nó (bật/tắt, cô lập lỗi). Test `StreamingResponseGeneratorTest` chuyển thành test của `AnswerTemplateFallback`, giữ nguyên 6 kịch bản.
4. **`TurnRunner` và facade.** Rút `runPipeline`/`respond`/`respondStreaming` vào `TurnRunner`. `ChatbotService` thành facade. Tạo `AgentFactory` gom việc đọc env.
5. **Ranh giới và dọn dẹp.**
   - `ArchitectureTest`.
   - Bỏ `getenv` khỏi `Harness/`.
   - Chia nhỏ các hàm > 80 dòng trong `Harness/` (mục 1) thành hàm private có tên nói lên việc nó làm.
   - Dọn định dạng lỗi (dòng trống thừa trong `FreeShippingNudge`, `{` dính dòng trong `createToolGateway`).
6. **Tài liệu.**
   - `src/Harness/README.md` và README từng component.
   - Cập nhật `CLAUDE.md`, `.claude/rules/architecture.md`, `README.md`, `docs/chatbot-spec.md`, `docs/technical-spec.md`, gồm cả mục "thêm intent/tool" và "thêm middleware".

Tôi chọn một nhánh và một PR vào `develop`, commit theo từng giai đoạn để review được từng bước.

## 9. Kiểm chứng

- **Không sửa expectation:** mọi test hiện có và 40 eval case. Test chỉ được đổi đường dẫn file và các dòng gọi class đã đổi chỗ.
- **Test mới:**
  - `ArchitectureTest`.
  - Test cho `MiddlewareStack`: thứ tự, chặn ở `beforeTurn`, lồng nhau ở `wrapModelCall`, cô lập lỗi ở `afterTurn`.
  - Test cho từng middleware.
  - Test cho `AgentFactory`: thiếu middleware `required` thì báo lỗi; bỏ `FreeShippingNudge` khỏi config thì lượt chat có giỏ hàng gần ngưỡng không còn câu gợi ý.
- **Chạy thật trên localhost:**
  - `scripts/dev/smoke.sh`.
  - `scripts/smoke_chat_stream_latency.mjs`.
  - Câu phối đồ "Có 800k, cần đồ đi phỏng vấn…" cho kết quả giống hệt trước refactor (cùng 2 set, cùng id).
  - Một câu checkout bị từ chối.
  - Một lượt có giỏ hàng gần ngưỡng có câu gợi ý free ship.
- **CI xanh toàn bộ** trên PR.

## 10. Phần để người thực hiện tự quyết

- Tên các hàm private khi chia nhỏ hàm dài.
- Cách `ArchitectureTest` nhận diện tham chiếu class (regex trên mã nguồn là đủ).
- Thời điểm sửa đường dẫn trong docblock.
- Các README component ghi chi tiết tới đâu, miễn 5–15 dòng và đủ 3 ý ở mục 4.

## 11. Các quyết định tôi tự đưa ra (cần người dùng xem lại khi đọc)

1. Tách thêm `Planning/` riêng khỏi `Loop/`, và tạo `Turn/` cho phần điều phối.
2. `ProductConstraintVerifier` ở lại `Evidence/`, không làm middleware, vì là luật cứng.
3. `CheckoutGuardrail` và `AnswerTemplateFallback` là middleware `required`: không tắt được. Middleware tắt được là `FreeShippingNudge` và `TraceOutbox`.
4. Lượt chat bị guardrail chặn vẫn nạp memory, vẫn để LLM viết lại câu từ chối, vẫn chạy `afterTurn`, trace, log và lưu lịch sử. Khác biệt duy nhất: câu checkout có xung đột sẽ bị từ chối thay vì được hỏi lại.
5. Chỉ làm 3 điểm móc. Không làm `wrapToolCall`.
6. Bảng phụ thuộc là luật bắt buộc. Tách `CheckoutDetector` làm nguồn duy nhất cho regex checkout.
7. `LLMResponse`, `ToolCall` và `LLMTransportException` thuộc `Harness/Model/`. `src/Llm/` chỉ chứa provider. Thông tin harness đi qua khóa `$options['harness']`, và `LLMResponse` có thêm field `fallbackReason`.
8. Giữ `ChatbotService` làm facade với chữ ký cũ. `TurnResult` vẫn là mảng.
9. Ghi trace chuyển lên trước `saveMessages`.
10. Một nhánh, một PR, commit theo giai đoạn.
11. `ChatStreamProtocol` chuyển sang `src/Http/Support/`. `LangfuseTraceOutbox`, `LangfuseOtlpTracePublisher` và `Cache` ở lại `Infrastructure`.
12. Test lắp ráp agent là integration test.
