# Spec: Refactor toàn repo theo trách nhiệm, harness tách theo component (agent = harness + LLM)

- Ngày: 2026-10-22
- Trạng thái: CHỜ DUYỆT
- Nhánh: `refactor/harness-components` (tách từ `develop`)
- Thứ tự (người dùng chọn): **phần A, harness trước**; sau đó **phần B, website và nghiệp vụ**.

## 1. Bối cảnh và lý do

Người dùng thấy code khó đọc, khó tái sử dụng vì logic bám chặt vào nhau. Rà soát ngày 2026-10-22 cho kết quả:

- **Không có phụ thuộc vòng giữa các module.** Chiều phụ thuộc vẫn đúng: `Http → Chatbot → Shop → Infrastructure`, nên giữ được khung kiến trúc.
- **`ChatbotService` ôm hết mọi việc.**
  - Phụ thuộc 22 class, tự `new` 17 chỗ.
  - Tự đọc env để chọn LLM và transport.
  - `runPipeline` dài 121 dòng.
  - Tự gọi nudge free ship, log và trace Langfuse. Muốn tắt nudge hay đổi LLM đều phải sửa class này.
- **`src/Chatbot/Pipeline/` là thư mục phẳng 23 class, khoảng 4.200 dòng.** Trộn lẫn intent, plan, chấm bằng chứng và sinh câu trả lời. Người đọc không thấy agent gồm những bộ phận nào.
- **LLM nằm lẫn trong harness.** Khoảng 10 class tự đọc `getenv()`.
- **Nhiều hàm quá dài:**
  - `EvidenceExecutionLoop::run`: 116 dòng
  - `PlanValidator::validateUseCase`: 86 dòng
  - `CapabilityRegistry::knownMetadata`: 99 dòng
  - `DeepSeekProvider::chat` (100 dòng) và `chatStream` (110 dòng) lặp nhau.
- **Phần website viết SQL thẳng trong trang.** `public/` có 63 câu SQL viết thẳng. 9 trang chính không gọi service nào.
- **Logic giỏ hàng và đặt hàng tồn tại hai bản, và hai bản khác nhau.**
  - API (`OrderService`) kiểm tra đủ số lượng tồn kho, trừ kho có khóa, ghi trạng thái `Chờ xử lý`.
  - Trang `public/process_checkout.php` mà khách thật dùng thì **không trừ kho**, chỉ kiểm tra `stock > 0`, ghi trạng thái `pending`, và truy vấn N+1.
  - `add_to_cart.php` không lưu size.
- **Phần website gần như không có test.** `CartService`, `OrderService`, `ProductService` không có test trực tiếp. `public/*` chỉ được kiểm bằng smoke (HTTP 200).

**Triết lý (người dùng chốt):** agent = harness + LLM, theo tinh thần bài "How to Build a Custom Agent Harness" của LangChain.
- LLM là một bộ phận cắm vào qua interface.
- Harness đưa đúng context cho LLM ở từng bước.
- Middleware là cơ chế cắm-rút thật.
- Phần còn lại của repo tách **theo trách nhiệm**: `app/api`, `core`, `service`, `model`, `repository`, `tests`…

## 2. Mục tiêu, phi mục tiêu, phản mục tiêu

**Mục tiêu (tiêu chí "xong", người dùng chốt):**
1. Người lạ mở `src/` là thấy ngay repo gồm những tầng nào. Mở `src/harness/` là thấy ngay agent gồm những bộ phận nào.
2. Mỗi tầng và mỗi component có một interface rõ ràng ở ranh giới.
3. Thay một bộ phận không phải sửa bộ phận khác. Các ví dụ chuẩn:
   - Đổi LLM provider: chỉ đụng `src/llm_providers/` và cấu hình.
   - Tắt nudge free ship: bỏ một dòng trong `config/harness.php`.
   - Đổi cách lưu giỏ hàng: chỉ đụng `CartRepository`.
4. Hành vi bên ngoài giữ nguyên, trừ đúng một chỗ được nêu ở mục 8.4 (sửa bug checkout). Toàn bộ unit test và 40 eval case pass **mà không sửa expectation**.

**Phi mục tiêu:**
- Không chia harness theo use case. Người dùng chấp nhận đánh đổi: logic một intent vẫn trải qua nhiều component.
- Không thêm tính năng, không đổi API HTTP, không đổi schema DB, không đổi URL trang.
- Không đổi giao diện trang (HTML/CSS).
- Không đổi `mcp-server/` (TypeScript), trừ khi bắt buộc.
- Không chuyển sang namespace PSR-4. Repo dùng Composer classmap, không namespace. Giữ nguyên.

**Phản mục tiêu (dù chạy được vẫn coi là thất bại):**
- Đổi tên class hàng loạt khiến diff không đọc nổi. Nguyên tắc: di chuyển file trước, đổi tên thật ít, chỉ đổi khi tên cũ sai nghĩa.
- Tạo interface hoặc tầng trừu tượng không có người dùng thật ("cho sau này"). Mỗi interface, repository và điểm móc phải có người dùng ngay trong dự án này.
- Middleware hoặc cấu hình tắt được guardrail cứng (thẻ sản phẩm phải có trong catalog, chặn checkout).
- Một commit nào đó để test hoặc eval đỏ.

## 3. Cấu trúc đích

```
public/                    web root (giữ nguyên URL): mỗi trang chỉ còn ~5–15 dòng gọi vào src/app/web
templates/                 view (header/footer/chat widget, giữ nguyên)
config/                    chatbot_tools.php (manifest), harness.php (MỚI), db.php
database/                  schema.sql + migrations (giữ nguyên)
scripts/                   điểm vào CLI mỏng (giữ đường dẫn, CI và docker đang gọi)
src/
  app/                     ĐIỂM VÀO: nhận request, gọi service, trả kết quả. Không SQL, không nghiệp vụ.
    api/                   routes.php + controllers REST (từ src/Http); support/ChatStreamProtocol
    web/                   handler cho từng trang storefront/admin (logic đang nằm trong public/*.php)
  core/                    hạ tầng dùng chung, không biết gì về cửa hàng
    bootstrap.php, config/, database/ (kết nối, MigrationRunner), cache/, http/ (helpers),
    observability/ (LangfuseTraceOutbox, LangfuseOtlpTracePublisher)
  service/                 NGHIỆP VỤ, không SQL
    catalog/  cart/  order/  size/  outfit/  freeship/  knowledge/  user/
  repository/              MỌI câu SQL, mỗi bảng hoặc nhóm bảng một class
    ProductRepository, CategoryRepository, CartRepository, OrderRepository, UserRepository,
    ReviewRepository, OutfitCatalogRepository, ChatSessionRepository, ChatMemoryRepository,
    ChatConversationRepository
  model/                   đối tượng dữ liệu thuần (không SQL, không I/O)
    OutfitItem, OutfitSet, OutfitRequest, OutfitResult, OutfitSlot, StyleTag, FreeShippingPolicy…
  harness/                 agent = harness + LLM (mục 4–7)
    README.md  llm/  turn/  intent/  planning/  loop/  tools/  evidence/  response/  memory/  middleware/
  agent/                   AgentFactory + ChatbotService (facade): nơi ghép harness với LLM
  llm_providers/           provider LLM cụ thể (DeepSeekProvider). Nằm NGOÀI harness.
tests/
  unit/<cùng cấu trúc src>/…   integration/…   (đổi từ tests/Unit, tests/Integration)
```

Quy ước và lý do đặt tên (do tôi quyết):
- **Tên thư mục viết thường**, giống ví dụ của người dùng (`app/api`, `core`…). Tên class giữ PascalCase như hiện nay.
- Mọi code PHP vẫn nằm dưới `src/`. Nhờ vậy Dockerfile (`COPY src/ src/`), `composer.json` (classmap `src/`), PHPStan và các `require` tới `src/bootstrap.php` chỉ phải đổi đúng đường dẫn bootstrap. `src/bootstrap.php` sẽ được giữ làm file một dòng chuyển tiếp sang `src/core/bootstrap.php`, để 20 trang `public/` chưa refactor vẫn chạy.
- **Cổng LLM của harness tên `harness/llm/`**, provider tên `llm_providers/`. Tôi tránh tên `harness/model/` vì dễ nhầm với tầng `model/` (đối tượng dữ liệu). Code dễ đọc là mục tiêu chính.
- Domain của phối đồ và free ship (`OutfitBuilder`, `FreeShippingGapAdvisor`…) là nghiệp vụ cửa hàng, nên chuyển sang `service/outfit` và `service/freeship`. `harness/tools` chỉ chứa phần nối tool với service. Knowledge (RAG) cũng là service, vì REST `/api/knowledge/search` dùng chung.

### 3.1 Luật phụ thuộc giữa các tầng

```
app ─→ agent ─→ harness ─→ service ─→ repository ─→ core
 │                 │           └──→ model ←──┘
 └──────────→ service          llm_providers ─→ harness/llm (chỉ interface)
```

- `app` dùng `service` và `agent`. Không dùng `repository` trực tiếp, không viết SQL.
- `service` dùng `repository`, `model`, `core`. Không viết SQL, không đọc `$_POST`/`$_SESSION`.
- `repository` dùng `model`, `core`. Đây là **nơi duy nhất có SQL** (`prepare`/`query`/`exec`).
- `model` không dùng tầng nào.
- `core` không dùng tầng nào trên nó.
- `harness` dùng `service`, `model`, `core`. Không dùng `app`, `agent`, `llm_providers`. Không đọc env.
- `agent` là nơi duy nhất ghép `harness` với `llm_providers` và đọc cấu hình của chatbot.

Ngoại lệ được ghi rõ: `core/database/MigrationRunner` có SQL (DDL, bảng `_migrations`). `core/observability/LangfuseTraceOutbox` có SQL trên bảng outbox của nó. Lý do: đây là hạ tầng, không phải dữ liệu nghiệp vụ.

**Test kiến trúc** (`tests/unit/ArchitectureTest.php`) quét mã nguồn để cưỡng chế 3.1 và bảng ở mục 4. Nó kiểm tra: tham chiếu class giữa các tầng, có SQL ngoài `repository/` hay không (trừ các ngoại lệ trên), và có `getenv`/`$_ENV`/`$_SERVER` trong `harness/`, `service/`, `repository/` hay không.

## 4. Phần A: harness (`src/harness/`)

### 4.1 Component

| Component | Chứa (từ code hiện có) | Cửa vào công khai | Được dùng (ngoài service/model/core) |
|---|---|---|---|
| `llm/` | `LLMProvider`, `StreamingLLMProvider`, `LLMResponse`, `ToolCall`, `LLMTransportException` (tách file), `ModelCall`, `MiddlewareModel` | interface + DTO | middleware (chỉ `Middleware` và `MiddlewareStack`) |
| `turn/` | `TurnRunner` (thay `runPipeline`), `TurnContext`, `TurnResult` | `TurnRunner::run()` | mọi component |
| `intent/` | `IntentResolver`, `DeterministicIntentParser`, `PartialParseResult`, `ConflictDetector`, `ConflictResolver`, `MergeEngine`, `LlmInputParser`, `SemanticEntityEnricher`, `TurnTierGate`, `CheckoutDetector` (MỚI: regex `isCheckout` tách ra) | `IntentResolver::resolve()`, `CheckoutDetector` | llm, planning |
| `planning/` | `ChatbotToolManifest`, `CapabilityRegistry`, `ToolPlanner`, `PlanValidator` | 4 class trên | — |
| `loop/` | `EvidenceExecutionLoop`, `ParallelToolExecutor`, `NoProgressDetector`, `EvidenceDecisionRouter` | `EvidenceExecutionLoop::run()` | planning, tools, evidence |
| `evidence/` | `EvidenceNormalizer`, `ProductConstraintVerifier`, `ObservationEvaluator`, `LightweightEvidenceScorer` | 3 class đầu | — |
| `tools/` | `ChatbotToolGateway` (interface), `ToolDefinitionCatalog`, `ToolRegistry`, `McpToolGateway`, `ShadowToolGateway`, `ToolApplicationService` | `ChatbotToolGateway` | — |
| `response/` | `ResponseGenerator`, `StreamingResponseGenerator` | 2 class | llm |
| `memory/` | `ChatbotMemoryStore` + `ChatbotConversationStore` (interface), `ChatbotMemory`, `PdoChatbotConversationStore` (chuyển SQL sang `ChatMemoryRepository`/`ChatConversationRepository`), `ChatbotSessionContext` (SQL sang `ChatSessionRepository`) | interface | — |
| `middleware/` | `Middleware`, `BaseMiddleware`, `MiddlewareStack`, `CheckoutGuardrail`, `AnswerTemplateFallback`, `FreeShippingNudge`, `TraceOutbox` | `Middleware`, `MiddlewareStack` | turn (chỉ `TurnContext`/`TurnResult`), llm, intent (`CheckoutDetector`), response (câu từ chối), evidence (`productCards`) |

Bảng này là **luật bắt buộc**: code phải sửa cho khớp bảng, không sửa bảng cho khớp code.
- `TurnContext`/`TurnResult` là kiểu dữ liệu thuần. Middleware chỉ dùng hai kiểu này, không dùng `TurnRunner`.
- `MiddlewareModel` chỉ biết `Middleware` và `MiddlewareStack` (class cụ thể: nó nhận stack qua constructor và gọi `MiddlewareStack::wrapModelCall`), không biết middleware cụ thể nào.
- Mỗi luật chỉ có **một nguồn**: regex checkout ở `CheckoutDetector`, câu từ chối là hằng số trong `ResponseGenerator`. Guardrail và lớp phòng thủ thứ hai trong pipeline cùng dùng hai nguồn đó, không chép lại.

Mỗi component có `README.md` ngắn (5–15 dòng) nêu 3 điều: làm gì, cửa vào là gì, được dùng ai. `src/harness/README.md` vẽ sơ đồ một lượt chat và có bảng trên.

### 4.2 Cổng LLM (`harness/llm/`)

- `llm_providers/DeepSeekProvider` hiện thực cổng này. Thêm provider mới nghĩa là thêm một file và một nhánh trong `AgentFactory`. Tách lõi HTTP/curl và phân loại lỗi dùng chung của `chat` và `chatStream`.
- `AgentFactory` bọc provider thật trong **`MiddlewareModel`**. Đây là decorator hiện thực `LLMProvider` và `StreamingLLMProvider`, chuyển mọi lời gọi qua `MiddlewareStack::wrapModelCall`. Component chỉ thấy `LLMProvider`.
- Người gọi đặt khóa dành riêng `$options['harness'] = ['purpose' => 'input_parse'|'entity_enrich'|'answer', 'grounded_draft' => ?string]`. `MiddlewareModel` tách khóa này ra để dựng `ModelCall` (`purpose`, `messages`, `options`, `groundedDraft`, `onDelta`, `stream`), rồi gỡ nó trước khi gọi provider. Provider không bao giờ thấy khóa này.
- `LLMResponse` có thêm field `?string $fallbackReason` (mặc định `null`). `StreamingResponseGenerator` đọc field này để trả `['text', 'fallback']` như hiện nay. Nhờ vậy `latency.streaming=template_fallback` và log `chatbot_stream_llm` không đổi.

### 4.3 Middleware: cơ chế cắm-rút

```php
interface Middleware
{
    /** Trả TurnResult để chặn lượt chat (bỏ qua intent/tool/evidence), hoặc null để đi tiếp. */
    public function beforeTurn(TurnContext $turn): ?TurnResult;

    /** Bọc một lời gọi LLM. $next thực hiện lời gọi thật (hoặc middleware kế tiếp). */
    public function wrapModelCall(ModelCall $call, callable $next): LLMResponse;

    /** Nhận kết quả lượt chat, có thể bổ sung (ví dụ câu gợi ý), trả kết quả mới. */
    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult;
}
```

- `BaseMiddleware` mặc định không làm gì ở cả 3 điểm móc.
- **Chỉ 3 điểm móc**, vì chỉ 3 điểm này có người dùng thật. Không làm `wrapToolCall`: retry tool hiện thuộc ngân sách của vòng lặp bằng chứng, chuyển ra ngoài sẽ đổi ngữ nghĩa.
- `MiddlewareStack` chạy theo thứ tự trong `config/harness.php`:
  - `beforeTurn`: tuần tự, gặp middleware chặn đầu tiên thì dừng.
  - `wrapModelCall`: lồng kiểu củ hành, middleware đầu danh sách nằm ngoài cùng.
  - `afterTurn`: tuần tự.
- **Cô lập lỗi:** lỗi `afterTurn` của middleware tùy chọn bị bắt lại và ghi log `{operation: middleware_<name>, success: false}`. Lỗi của middleware `required` thì để lan ra.

| Middleware | Điểm móc | Lấy từ | Tắt được? |
|---|---|---|---|
| `CheckoutGuardrail` | `beforeTurn` | `isCheckout` (ưu tiên số 1 trong `inferIntent`) + câu từ chối trong `ResponseGenerator` | **Không** (`required`) |
| `AnswerTemplateFallback` | `wrapModelCall` (`purpose=answer`) | try/catch + `fallback()` của `StreamingResponseGenerator` | **Không** (`required`: "lượt chat không bao giờ hỏng vì LLM") |
| `FreeShippingNudge` | `afterTurn` | `ChatbotService::freeShippingNudge()` + `withNudgeCard()` + class `FreeShippingNudge` | Có |
| `TraceOutbox` | `afterTurn` (cuối danh sách) | `ChatbotService::enqueueLangfuseTrace()` | Có |

```php
// config/harness.php
return [
    'middleware' => [
        CheckoutGuardrail::class,      // required: AgentFactory từ chối khởi động nếu thiếu
        AnswerTemplateFallback::class, // required
        FreeShippingNudge::class,
        TraceOutbox::class,
    ],
];
```

**Giữ nguyên hành vi:**

- **`CheckoutGuardrail`:**
  - Trả `primary_intent=unsupported_checkout`, `response_type` và câu từ chối giống hiện nay, không gọi tool. Đây là những gì 8 eval case nhóm guardrail kiểm tra.
  - Dùng `CheckoutDetector` trên đúng chuỗi đã chuẩn hoá mà `inferIntent` nhận.
  - Các nhánh `unsupported_checkout` trong pipeline giữ nguyên, làm lớp phòng thủ thứ hai cho trường hợp LLM parser suy ra intent này.
- **Lượt chat bị chặn chỉ bỏ phần giữa** (intent, tool, evidence):
  - `beforeTurn` chạy **sau** bước nạp memory (`rememberUserMessage`).
  - Câu từ chối vẫn đi qua bước sinh câu trả lời. Ở chế độ stream, LLM vẫn viết lại câu này như hiện nay.
  - Sau đó vẫn chạy `afterTurn`, trace, log `routing_pipeline`, `saveMessages`, `refreshSummary`.
  - **Khác biệt duy nhất:** một câu vừa đòi checkout vừa có xung đột chưa giải được, hiện đang được hỏi lại, sau refactor sẽ bị từ chối luôn. Từ chối an toàn hơn, và trường hợp này không có trong eval.
- **`AnswerTemplateFallback`:** giữ đúng 6 hành vi đang có test:
  - Chưa có token nào → gửi bản nháp.
  - Đã gửi một phần → nối `"\n\n" + bản nháp`.
  - Trả về rỗng → gửi bản nháp.
  - Lỗi không phải lỗi transport → vẫn fallback.
  - Không có bản nháp → ném lại lỗi.
  - Giữ log và `latency.streaming`.
- **`FreeShippingNudge`:**
  - Chỉ chạy khi có user, đúng intent mua sắm, mỗi fingerprint giỏ hàng một lần.
  - Ở chế độ stream, câu gợi ý là **một delta riêng sau câu trả lời** (qua `TurnContext::emit`).
  - Thẻ sản phẩm của nudge gộp vào `products`/`cards`, không trùng id.

### 4.4 Turn, AgentFactory và facade

- **`TurnRunner::run(TurnContext): TurnResult`** gộp `runPipeline` + `respond` + `respondStreaming`. Stream hay không chỉ khác ở chỗ `TurnContext` có `emit` hay không. Hàm chính đọc từ trên xuống, mỗi bước là một hàm private ngắn:
  1. nạp memory
  2. middleware `beforeTurn`
  3. resolve intent (hoặc trả lời làm rõ)
  4. vòng lặp bằng chứng
  5. sinh câu trả lời
  6. middleware `afterTurn`
  7. lưu lịch sử, refresh summary
- Ghi trace (`TraceOutbox`) nằm trong `afterTurn`, tức chạy trước `saveMessages`. Hai thao tác ghi DB này độc lập nhau.
- **`TurnResult`** vẫn là mảng có kiểu, giống kết quả hiện nay (`message`, `answer`, `products`, `cards`, `response_type`, `primary_intent`, `latency`, `trace_id`…). Nhờ vậy controller và eval không phải sửa.
- **`AgentFactory::create(PDO, sessionId, userId, ?overrides)`:**
  - Đọc env **một lần** thành `HarnessConfig`.
  - Chọn transport tool (`internal`/`mcp`/`shadow`) và LLM (kể cả `CHATBOT_FORCE_L0`, `CHATBOT_TEMPLATE_RESPONSE`).
  - Dựng `MiddlewareStack` từ `config/harness.php` và kiểm tra middleware `required`.
  - Gọi `FreeShippingPolicy::fromEnvironment()` (và các `fromEnvironment` khác) chỉ ở đây.
- Các class đang tự đọc env (`KnowledgeRetriever`, `McpToolGateway`, `FreeShippingPolicy`, `ResponseGenerator`, phần env của `ChatbotService`) nhận giá trị qua constructor. Giá trị mặc định giữ nguyên.
- **`ChatbotService` thành facade mỏng trong `src/agent/`.** Giữ chữ ký cũ vì 2 controller và `eval/AgentEvalCaseRunner.php` gọi nó; hiện chưa có unit test nào chạy trọn một lượt. Các tham số tùy chọn cũ chuyển thành overrides.
- Test lắp ráp agent (bỏ nudge khỏi config thì không còn câu gợi ý; thiếu middleware `required` thì báo lỗi) là **integration test** trên `shop_test`, dùng transport `internal` và không dùng LLM, giống eval.

## 5. Phần B: website và nghiệp vụ

### 5.1 Repository

- Mọi SQL trong `src/Shop/*`, `src/Http/Controllers/*`, `public/*` và phần memory/session của chatbot chuyển vào `src/repository/`.
- Mỗi hàm repository làm **một** truy vấn có tên nói lên việc nó làm, ví dụ `CartRepository::itemsWithStock(int $userId)`, `ProductRepository::decrementStock(int $id, int $qty): bool`.
- Repository nhận `PDO` qua constructor. Câu SQL giữ nguyên chữ, không tối ưu trong dự án này, **trừ** truy vấn N+1 của checkout (mục 5.4).
- `ProductService::executeSearchProductsDirect` (107 dòng, dựng SQL động) tách thành: phần dựng điều kiện tìm kiếm (service), và phần chạy truy vấn (`ProductRepository::search(ProductSearchCriteria)`). `ProductSearchCriteria` thuộc `model/`.

### 5.2 Service

- `src/Shop/*` chuyển sang `src/service/<miền>/`: `ProductService`, `CartService`, `OrderService`, Catalog (`CatalogVariantHydrator`, `ProductReranker`, `CatalogTaxonomy`, `ProductAttributeNormalizer`, `CatalogVariantBackfill`), size, outfit, freeship, knowledge.
- Service gọi repository thay vì `$this->pdo`.
- Logic nghiệp vụ đang nằm trong trang `public/` mà chưa có service tương ứng chuyển vào service mới: đăng nhập/đăng ký (`UserService`), đánh giá sản phẩm (`ReviewService`), quản trị sản phẩm/đơn/người dùng (dùng service sẵn có hoặc `Admin*Service`).

### 5.3 App

- `src/app/api/`: `routes.php` + controller REST hiện có. Controller chỉ đọc request, gọi service, trả JSON.
- `src/app/web/`: mỗi trang có một handler (ví dụ `CartPage::handle(): array` trả dữ liệu cho view).
- `public/<trang>.php` chỉ còn: nạp bootstrap, gọi handler, include header, view, footer.
- HTML của từng trang chuyển sang `templates/pages/<trang>.php`, **giữ nguyên markup**, để smoke và giao diện không đổi.
- Xác thực, phân quyền admin và CSRF/flash (nếu có) giữ đúng thứ tự kiểm tra như hiện nay. Bản vá `exit` của `admin/add_product.php` vẫn phải giữ.

### 5.4 Thay đổi hành vi duy nhất: checkout

- Trang `public/process_checkout.php` và `add_to_cart.php` chuyển sang dùng `OrderService`/`CartService`, tức cùng một logic với API.
- Hệ quả mong muốn: checkout trên web **trừ kho có khóa**, kiểm tra đủ số lượng, ghi trạng thái giống API, hết N+1; thêm vào giỏ lưu size.
- Đây là sửa bug, được nêu rõ trong PR.
- Thông báo cho người dùng (alert, redirect) giữ nguyên chữ.
- Trạng thái đơn tạo từ web đổi từ `pending` sang `Chờ xử lý`, giống API. Trang admin quản lý đơn phải hiển thị và lọc được trạng thái này. Người thực hiện phải kiểm tra trang admin trước khi đổi.

### 5.5 Lưới an toàn trước khi động vào website

- Trước khi refactor một trang hoặc service chưa có test, viết **integration test chốt hành vi hiện tại** (characterization) trên `shop_test` cho service tương ứng: Cart, Order, Product, User, Review.
- Với trang web, viết thêm kịch bản HTTP trong `scripts/dev/smoke.sh`, hoặc một smoke có đăng nhập (giữ cookie phiên), cho các luồng: đăng nhập, thêm vào giỏ, cập nhật giỏ, checkout, xem đơn, admin sửa sản phẩm.
- Riêng checkout: test chốt hành vi của **API** (bản đúng). Sau khi đổi, trang web phải cho kết quả giống API.

## 6. Kiểm chứng

- **Không sửa expectation** của bất kỳ test hiện có nào hay của 40 eval case. Test chỉ đổi đường dẫn và các dòng gọi class đã đổi chỗ.
- **Test mới:**
  - `ArchitectureTest`.
  - `MiddlewareStack`: thứ tự, chặn, lồng nhau, cô lập lỗi.
  - Từng middleware.
  - Integration test lắp ráp agent.
  - Integration test chốt hành vi cho các service website.
  - Repository: chạy trên SQLite fixture nếu câu SQL tương thích, không thì integration.
- **Sau mỗi commit:** unit, PHPStan, integration, eval 40 case, MCP test, `docker compose config`.
- **Chạy thật trên localhost** (sau phần A và sau phần B):
  - `scripts/dev/smoke.sh` và smoke latency SSE.
  - Câu phối đồ "Có 800k, cần đồ đi phỏng vấn…" cho cùng 2 set, cùng id như trước.
  - Một câu checkout bị từ chối.
  - Một lượt có giỏ hàng gần ngưỡng có câu gợi ý free ship.
  - Luồng mua hàng trên web: thêm giỏ (có size) → checkout → kho giảm đúng → đơn hiện trong trang đơn và trang admin.
- **CI xanh** trên PR.

## 7. Chiến lược thực hiện

**Điều kiện tiên quyết:** PR #14 (sửa thứ tự `composer install` trong job Integration) merge vào `develop`. PR này đã xanh, eval 40/40 lần đầu chạy trên CI. Nhánh refactor rebase lên `develop` sau đó.

**Phần A: harness.** Mỗi giai đoạn gồm một hoặc vài commit, xanh toàn bộ sau mỗi commit.

1. **Khung thư mục và di chuyển file thuần.**
   - `git mv` toàn bộ `src/` sang cấu trúc mục 3: harness, agent, llm_providers, core, app/api, service, model. Kể cả test (`tests/unit/…`, `tests/integration/…`, sửa `phpunit.xml`).
   - `composer dump-autoload`. `src/bootstrap.php` thành file chuyển tiếp.
   - Không sửa logic. Classmap không quan tâm thư mục.
2. **Tách LLM:** `harness/llm/` + `llm_providers/`. `LLMFactory` vào `AgentFactory`. Tách lõi HTTP của `DeepSeekProvider`.
3. **Middleware:** cơ chế + `config/harness.php`. Chuyển 4 middleware, mỗi middleware một commit kèm test. `StreamingResponseGeneratorTest` thành test của `AnswerTemplateFallback`, giữ 6 kịch bản.
4. **`TurnRunner`, `AgentFactory` và facade.**
5. **Ranh giới:**
   - `ArchitectureTest` (phần harness).
   - Bỏ env trong harness.
   - Tách `CheckoutDetector`.
   - Chia các hàm > 80 dòng trong harness.
   - Dọn lỗi định dạng.
6. **Tài liệu phần A:** README harness và README từng component.

**Phần B: website.**

7. Integration test chốt hành vi (mục 5.5).
8. **Repository**, mỗi miền một commit: product, cart, order, user, review, chat memory/session.
9. **Service mới** (User, Review, Admin…) và chuyển `Shop` cũ sang dùng repository.
10. **`app/web` + `templates/pages`**, mỗi nhóm trang một commit: catalog, giỏ, checkout, tài khoản, admin. Checkout đổi hành vi theo 5.4 ở commit riêng.
11. `ArchitectureTest` mở rộng: không SQL ngoài repository, không env ngoài agent/core. Phần A còn để lại các điểm sau; bản mở rộng phải bao phủ (và bước này phải sửa) chúng:
    - `service/freeship/FreeShippingService` vẫn gọi `FreeShippingPolicy::fromEnvironment()` khi không được truyền policy. Tool `suggest_freeship_items` (`ToolApplicationService`) tạo service không kèm policy, nên intent `freeship_gap` chưa thấy policy tùy chỉnh trong `HarnessConfig` cho tới khi sửa. Resolved in Part B (website layers).
    - `service/knowledge/KnowledgeRetriever` và `service/catalog/ProductReranker` vẫn đọc env. Resolved in Part B (website layers).
    - Controller trong `app/` (`app/api/controllers/Chatbot/index.php`, `stream.php`) tham chiếu thẳng class của harness (`ChatbotSessionContext`, `LLMTransportException`) thay vì đi qua `agent`. Resolved in Part B (website layers).
    - **Các lỗi trang có sẵn, giữ nguyên để khớp byte (known page bugs kept for byte identity)** cùng các việc tiếp theo, để sửa ở PR riêng:
      - redirect sau khi đã có output ở `checkout.php` (khách) và `register.php` (thành công). Lỗi ngầm trên prod: PHP-FPM `output_buffering=4096` che lỗi này trừ khi output trước redirect vượt 4 KB. Resolved in web follow-ups Task 1;
      - join `reviews r JOIN users u ON r.id = u.id` ở `product.php` (`ReviewRepository::forProductLegacyJoin`). Resolved in web follow-ups Task 1 (join `r.user_id = u.id`);
      - đích redirect của đăng nhập admin không nhất quán. Resolved in web follow-ups Task 2;
      - `admin/manage_orders.php` không có whitelist trạng thái. Resolved in web follow-ups Task 1 (`OrderService::STATUSES`);
      - cập nhật giỏ hàng qua GET (`UpdateCartAction`). Resolved in web follow-ups Task 3 (POST + CSRF);
      - liên kết GET đổi trạng thái (chỉ admin, nhưng CSRF được): `admin/manage_products.php?delete_id=` (xóa dòng và `unlink` ảnh), `admin/manage_users.php?action=lock|unlock&id=`, `?toggle_role=`. Resolved in web follow-ups Task 3 (POST + CSRF, `src/core/http/Csrf.php`).
    - Phát hiện khi thực hiện:
      - `admin/manage_users.php` đọc `$_SESSION['user_id']` cho admin từ env, vốn không có `user_id` (cảnh báo "Undefined array key"). Resolved in web follow-ups Task 1;
      - `admin/edit_product.php` / `manage_products.php` cảnh báo ở `$_FILES['image']` khi form được gửi không có phần file. Resolved in web follow-ups Task 1;
      - admin từ env (`user_id=0`) POST `add_to_cart.php` giờ thấy alert hết hàng, trước đây là lỗi FK của DB. Resolved in web follow-ups Task 4 (admin từ env không mua hàng, thông báo riêng);
      - thêm sản phẩm không có size vào dòng giỏ đã có sẽ đặt lại size về `S`. API làm y hệt; cần một quyết định riêng. Resolved in web follow-ups Task 4 (giữ size của dòng, `CartService::add`);
      - controller API `Cart/*` và `Orders/*` bắt `RuntimeException`, nuốt luôn cả `PDOException`. Hướng hội tụ là `ApiError` (`src/model/ApiError.php`). `OrderDetailsPage` cũng có vấn đề này. Resolved in web follow-ups Task 5 (lỗi DB là 500 chung, không lộ thông báo domain hay text exception);
      - `/api/chatbot/stream` kết thúc bằng `chat.error` khi không có LLM và template mode tắt. Resolved in web follow-ups Task 6 (stream câu trả lời template có căn cứ);
      - cầu MCP (`AgentFactory::executeTool`, gọi từ `Internal/mcp.php`) chạy mọi tool của `ToolApplicationService`, gồm cả giỏ hàng và checkout, không lọc theo `ToolDefinitionCatalog` như `ToolRegistry::execute`. Resolved in web follow-ups Task 7 (tool ngoài catalog bị từ chối như tool không biết: `InvalidArgumentException`, HTTP 400).
    - **Còn mở (still open), chưa sửa sau web follow-ups:**
      - không có `session_regenerate_id` khi đăng nhập và cookie phiên không có SameSite;
      - `Internal/mcp.php` ánh xạ `PDOException` sang 409 kèm text gốc và không ghi log;
      - `authenticate()` và một số trang (`CartPage`, `CheckoutPage`, `UpdateCartAction`, `ProfilePage`) không xử lý `PDOException`;
      - token CSRF hết hạn bị bỏ qua im lặng (no-op), người dùng không thấy thông báo;
      - nhánh admin từ env khi đăng nhập admin giữ lại `user_id` của khách hàng đang có trong phiên.
12. **Tài liệu:** `CLAUDE.md`, `.claude/rules/*`, `README.md`, `docs/chatbot-spec.md`, `docs/technical-spec.md`, gồm mục "thêm intent/tool", "thêm middleware", "thêm trang/endpoint".

Mỗi phần một PR vào `develop`. Phần A merge trước rồi mới mở nhánh phần B.

## 8. Phần để người thực hiện tự quyết

- Tên hàm private khi chia nhỏ hàm dài. Tên hàm repository, miễn nói rõ việc làm.
- Cách `ArchitectureTest` nhận diện tham chiếu và SQL (regex trên mã nguồn là đủ).
- Gom service admin thành một hay nhiều class.
- Mức chi tiết README component, miễn 5–15 dòng và đủ 3 ý.

## 9. Quyết định tôi tự đưa ra (cần người dùng xem lại)

1. Mọi code PHP vẫn dưới `src/`, các tầng là thư mục viết thường bên trong. `public/`, `templates/`, `config/`, `scripts/` giữ chỗ.
2. Harness là một khối riêng chia theo component. Các tầng `app/core/service/repository/model` áp dụng cho phần còn lại. Harness gọi xuống `service`/`repository`, không tự viết SQL.
3. Cổng LLM tên `harness/llm/`, provider tên `llm_providers/`, để tránh trùng nghĩa với tầng `model/`.
4. Outfit, freeship, knowledge là nghiệp vụ nên nằm ở `service/`; `harness/tools` chỉ nối tool với service.
5. `CheckoutGuardrail` và `AnswerTemplateFallback` là middleware `required`. `FreeShippingNudge` và `TraceOutbox` tắt được.
6. Lượt chat bị guardrail chặn vẫn nạp memory, vẫn để LLM viết lại câu từ chối, vẫn chạy afterTurn, log và lưu lịch sử. Riêng câu checkout có xung đột sẽ bị từ chối thay vì hỏi lại.
7. Chỉ 3 điểm móc. Không làm `wrapToolCall`.
8. Bảng phụ thuộc là luật bắt buộc, có test kiến trúc. Tách `CheckoutDetector` làm nguồn duy nhất cho regex checkout.
9. `ChatbotService` giữ làm facade với chữ ký cũ. `TurnResult` vẫn là mảng.
10. SQL giữ nguyên chữ khi chuyển vào repository, trừ N+1 của checkout.
11. Thay đổi hành vi duy nhất ngoài harness: checkout và thêm giỏ trên web dùng chung logic với API. Hệ quả là trừ kho, lưu size, và trạng thái đơn thành `Chờ xử lý`.
12. Hai PR: phần A rồi phần B. Test lắp ráp agent và test chốt hành vi website là integration test.
