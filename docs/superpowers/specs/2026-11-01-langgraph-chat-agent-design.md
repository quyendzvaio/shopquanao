# Spec: Thay luồng chat PHP bằng agent LangGraph (TypeScript), LLM tự gọi tool

- Ngày: 2026-11-01
- Trạng thái: ĐÃ DUYỆT (2026-11-01)
- Nhánh: `feat/langgraph-chat-agent` (tách từ `develop` tại 5ddb46c)
- Thay thế: phần "Chatbot architecture" trong `CLAUDE.md`, `.claude/rules/architecture.md` §Chat pipeline, `.claude/rules/eval.md`.

Ký hiệu: **[người dùng]** là quyết định của người dùng. **[đề xuất, người dùng đồng ý]** là đề xuất của Claude đã được người dùng chấp nhận. **[Claude chọn]** là lựa chọn của Claude, người dùng chưa duyệt riêng.

## 1. Bối cảnh và lý do

Luồng chat hiện tại gồm `DeterministicIntentParser` → `ToolPlanner` (manifest) → `EvidenceExecutionLoop` → `ResponseGenerator`, khoảng 7.000 dòng PHP trong `src/harness/`. Ở luồng này LLM không bao giờ chọn tool: PHP đoán ý định bằng regex rồi tra manifest. Người dùng thấy cách làm này không đúng tinh thần agent. Chạy thử ngày 2026-11-01 trên `shop_test` cho thấy những điểm yếu cụ thể:

| Câu khách | Bot hiện tại | Lý do |
|---|---|---|
| "quần baggy còn hàng" | trả 7 chiếc quần khác, không nói baggy (#69) đã hết | parser chỉ khớp "quần", từ "baggy" bị `parseUnresolvedSpans` bỏ đi mà không báo; lượt được coi là L0, không gọi LLM |
| "váy hai dây còn hàng" | trả 12 chiếc váy, không có #83 | giống trên |
| "mũ còn hàng", "khăn choàng còn hàng" | "bạn nói rõ hơn…" | bảng loại sản phẩm không có "mũ"/"khăn" nên ý định thành `unknown`. Catalog có #91 và #94 |
| "phối đồ đi tiệc, cao 160 nặng 50 mặc size gì" | chỉ phối đồ, bỏ qua câu hỏi size | mỗi lượt chỉ xử lý một ý định |

Bảng từ khoá không bao giờ đủ. Người dùng muốn LLM hiểu câu và tự chọn tool, còn các luật nghiệp vụ được giữ lại bằng một lớp policy.

## 2. Mục tiêu

1. **[người dùng]** Bỏ hẳn luồng chat PHP. Thay bằng một graph **LangGraph viết bằng TypeScript**, trong đó LLM tự gọi tool trong bộ tool chỉ-đọc của shop.
2. **[người dùng]** Dùng **LangChain.js v1 `createAgent` cùng middleware** (`beforeAgent`, `beforeModel`, `afterModel`, `afterAgent`, `wrapModelCall`, `wrapToolCall`), chạy trên LangGraph. Các luật cũ được dựng thành **lớp policy** bằng middleware này.
3. **[người dùng]** Graph có node agent (LLM chọn tool) nối hai chiều với node tools, cộng thêm **các cạnh rẽ nhánh tự định nghĩa**. Cạnh đầu tiên bắt buộc phải có: *mẫu khách hỏi đã hết hàng* → node tìm mẫu thay thế **cùng nhóm, cùng phong cách** → node **hỏi lại khách**. Đây là phương án 3 người dùng đã chọn: bot không tự hiện thêm sản phẩm, chỉ hỏi.
4. **[người dùng]** Các luật cứng hiện có vẫn đúng sau khi đổi (§6).
5. **[người dùng]** Bỏ bộ eval cũ (`eval/cases.jsonl`, 40 ca). Viết bộ eval mới cho graph gồm hai lớp (§9).

## 3. Không làm

- Không thêm tool nào ghi dữ liệu: không có giỏ hàng, đặt hàng hay thanh toán. Việc này giữ nguyên như trước.
- Không sửa widget hay storefront. Giao thức SSE với trình duyệt giữ nguyên (§5.4).
- Không chuyển phần dữ liệu (catalog, giỏ, đơn, RAG, rerank) sang Node.
- Không giữ luồng PHP cũ làm đường dự phòng khi LLM lỗi **[người dùng]** (§7).
- Không đổi nhà cung cấp LLM: vẫn đọc `LLM_*` trong `.env` như hiện tại.

## 4. Điều được coi là thất bại

- Bot thêm giỏ, đặt hàng, hoặc hứa đã làm những việc đó.
- Bot hiện thẻ sản phẩm, giá hoặc tồn kho không có trong kết quả tool của lượt đó.
- Khi LLM chết, lượt chat treo lâu hoặc lộ lỗi kỹ thuật cho khách.
- Một lượt gọi tool không giới hạn số lần, khiến tiền LLM tăng vọt.
- CI phụ thuộc vào LLM thật, nên đỏ hoặc xanh một cách ngẫu nhiên.

## 5. Kiến trúc

### 5.1 Chia việc **[đề xuất, người dùng đồng ý]**

```
Trình duyệt ──POST /api/chatbot/stream──▶ nginx ──▶ app (PHP)
                                                   │ phiên, origin, độ dài, rate limit (nginx)
                                                   │ lưu tin nhắn vào MariaDB
                                                   ▼
                                    POST http://chat-graph:<port>/turn   (mạng nội bộ Compose)
                                                   │
                                     chat-graph (Node 22, TypeScript)
                                     LangGraph + createAgent + middleware policy + LLM
                                                   │ tool qua MCP (mcp-server, trong tiến trình)
                                                   ▼
                                  app (PHP) /api/internal/mcp ── ToolApplicationService ── service/repository ── MariaDB
```

- **PHP giữ phần dữ liệu:** storefront, REST, DB, các tool (`ToolApplicationService`, `ToolDefinitionCatalog`, `/api/internal/mcp`), phiên chat và lịch sử tin nhắn.
- **Node (`chat-graph`) lo phần "nghĩ":** graph, LLM, các policy. Node lấy tool qua `mcp-server` có sẵn.
- **Service mới `chat-graph`** trong `docker-compose.yml`:
  - Chỉ nằm trong mạng nội bộ, không có cổng public và nginx không định tuyến tới nó.
  - PHP gọi tới bằng header `X-CHAT-GRAPH-TOKEN`, so sánh kiểu hằng thời gian (constant-time). **[Claude chọn]** Token là biến env mới `CHAT_GRAPH_TOKEN`, không dùng chung `MCP_SERVICE_TOKEN`.
- **[Claude chọn] Cách lấy tool:** `chat-graph` import `createMcpServer(principal)` từ `mcp-server`, tạo cho mỗi lượt một server có `principal.userId` bằng user_id do PHP gửi sang, nối bằng `InMemoryTransport` của MCP SDK, rồi nạp tool vào LangChain qua `@langchain/mcp-adapters`. Mỗi lượt không phải sinh thêm tiến trình con. Định nghĩa tool vẫn có một nguồn duy nhất là `mcp-server/src/tools.ts`. `mcp-server` gọi `/api/internal/mcp` theo `SHOP_INTERNAL_URL`, giá trị này phải trỏ tới `app` trong mạng Compose.
- Trình tự chạy: `chat-graph` phụ thuộc `app` healthy. `app` không phụ thuộc `chat-graph`; nếu `chat-graph` chết, PHP trả câu "hệ thống đang bận" (§7).

### 5.2 Graph

```
START
  └▶ policy_in            (beforeAgent: CheckoutGuardrail → END với câu từ chối cố định, không gọi LLM)
  └▶ agent ⇄ tools        (createAgent: LLM chọn tool; middleware giới hạn số lần, lọc tool, kiểm tra kết quả)
        │
        ├─ [cạnh: hết hàng] ─▶ find_alternatives ─▶ ask_alternatives ─▶ END
        │
        └─ [còn lại] ─▶ policy_out (kiểm tra câu trả lời và thẻ) ─▶ END
```

- **Cạnh "hết hàng"** chạy khi trong lượt có một kết quả `search_products` mà `out_of_stock_ids` khác rỗng (§8.1). **[Claude chọn]** Việc này không phụ thuộc vào chuyện LLM có truyền `in_stock:true` hay không, vì PHP luôn tính trường đó: có lọc tồn kho thì là các mẫu bị loại vì hết hàng, không lọc thì là trường hợp mọi mẫu trả về đều đã hết. System prompt vẫn dặn LLM truyền `in_stock:true` khi khách hỏi "còn hàng". Trường `out_of_stock_matches` (PR #23) được giữ, có giá trị bằng `count(out_of_stock_ids)`.
  - Ngay khi gặp kết quả đó, graph rẽ nhánh: tìm mẫu thay thế bằng code cố định. LLM nhận dữ liệu (mẫu đã hết, các mẫu thay thế) và tự viết cả câu trả lời; code kiểm tra câu đó, sai thì dùng câu hỏi cố định (§5.2a). **[người dùng, 2026-11-02]** thay cho cách cũ "LLM không được nhắc tới mẫu hết hàng, code ghép câu hỏi cố định": LLM không làm theo lệnh im lặng ổn định (S02/X01 nói lặp, M01 mất phần size).
  - **[Claude chọn]** Cạnh được dựng bằng code TypeScript cố định (một middleware hoặc conditional edge xét kết quả tool), không để LLM quyết. Người dùng muốn bước này phải luôn chạy giống nhau.
  - Câu có nhiều ý mà một ý chạm cạnh hết hàng: xem §5.2a.
- **`find_alternatives`** gọi tool mới `find_similar_in_stock` (§8.1) với `product_id` là mẫu đã hết. Node này không gọi LLM.
- **`ask_alternatives`** giữ câu LLM viết khi câu đó đạt kiểm tra (§5.2a); nếu không, dùng mẫu câu cố định **[Claude chọn]**:
  - Có từ 1 gợi ý trở lên: `"Mẫu {tên mẫu đã hết} đang hết hàng. Bạn có muốn xem {tên 1} hoặc {tên 2} không?"`. Khi chỉ có 1 gợi ý: `"…Bạn có muốn xem {tên 1} không?"`.
  - Không có gợi ý nào: dùng lại câu của PR #23, `"Shop có N mẫu {loại} nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé."`.
  - Không kèm thẻ sản phẩm, `response_type = clarification`.
  - Nếu có từ 2 mẫu hết trở lên, chỉ lấy mẫu đầu tiên trong `out_of_stock_ids`. Mảng này **xếp theo đúng thứ tự xếp hạng của lần tìm** (sau rerank), như thể không có bộ lọc tồn kho. **[Claude chọn]**
- **Lượt sau** (khách đáp "có", "ống rộng đi", "cái thứ 2"): không cần bộ nhớ riêng. LLM thấy câu hỏi ở lượt trước trong lịch sử (§5.3) rồi tự gọi `search_products` hoặc `get_product_detail`.
- Builder được thêm cạnh khác về sau. Spec này chỉ bắt buộc cạnh "hết hàng".

### 5.2a Câu nhiều ý chạm cạnh hết hàng **[người dùng: trả lời các ý còn lại rồi mới hỏi]**

Ví dụ: "quần baggy còn hàng không, mình cao 160 nặng 50 mặc size gì". Câu trả lời mong muốn: "Với 160cm, 50kg bạn mặc size S. Còn mẫu quần baggy thì đang hết hàng, bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?"

Cách chạy **[Claude chọn]**:

1. Cạnh hết hàng chạy ngay sau node tools (code cố định), gọi `find_alternatives` và lưu `{sold_out, alternatives}` vào state của graph.
2. Kết quả `search_products` (hoặc `get_product_detail`) mà LLM nhìn thấy được **thay** bằng ghi chú dữ liệu (`soldOutNote`): "Mẫu {tên} đã hết hàng. Mẫu thay thế còn hàng: {tên 1} (mã N) giá …; {tên 2} …. Trả lời các ý khác của khách trước (nếu có), sau đó báo mẫu {tên} đang hết hàng và hỏi khách có muốn xem {tên 1} hoặc {tên 2} không. Viết đúng tên các mẫu, không gợi ý mẫu nào khác." **[người dùng, 2026-11-02]**
3. Agent chạy tiếp như bình thường cho các ý còn lại, vẫn trong ngân sách §6, và viết một câu trả lời liền mạch cho mọi ý. **[người dùng, 2026-11-02]** Nếu câu trả lời cuối của agent rỗng, hoặc (lượt hết hàng) không đạt kiểm tra ở bước 4, middleware đưa vòng lặp quay lại model **một lần**, vẫn có tool, kèm lời nhắc trả lời đủ mọi ý; nhờ vậy ý bị bỏ (ví dụ size ở M01) vẫn gọi được tool. Lần quay lại lỗi thì giữ câu đã viết và đi tiếp tới `policy_out`, không thành busy.
4. `policy_out` kiểm tra câu đó bằng code (`answersSoldOut`): có tên mẫu đã hết, có cụm báo hết hàng, và (khi có gợi ý) có tên hoặc `mã N` của ít nhất một mẫu thay thế. Giá và mã của các mẫu thay thế được tính là có căn cứ (kết quả `find_similar_in_stock` nằm trong dữ liệu kiểm tra grounding) nhưng không bao giờ thành thẻ. Câu không đạt dùng chung một lần gọi lại với lỗi grounding/markup. Vẫn không đạt (hoặc hết ngân sách, hoặc lần gọi lại lỗi) thì quay về cách cũ: phần LLM viết (nếu khác rỗng, đã qua `policy_out`) cộng câu hỏi theo mẫu ở trên, mẫu câu dùng "Còn mẫu …" khi ghép; LLM trả rỗng thì chỉ còn câu hỏi.
5. Thẻ sản phẩm của các ý khác (ví dụ set phối đồ) vẫn được hiện. Không có thẻ nào cho mẫu hết hàng hay cho các mẫu gợi ý. `response_type = clarification`.
6. Câu chỉ có một ý ("quần baggy còn hàng") vẫn tốn thêm một lần gọi LLM; lần gọi đó viết câu báo hết hàng và hỏi về mẫu thay thế.

### 5.3 Giao kèo PHP ↔ Node **[Claude chọn]**

`POST /turn`, body JSON:

```json
{ "message": "…", "user_id": 12 | null, "session_id": 345,
  "history": [{"role": "user|assistant", "content": "…"}],
  "memory": {"summary": "…", "slots": {…}, "profile": {…} | null},
  "freeship_fingerprint": "…" | null,
  "stream": true }
```

- **`history`**: 6 tin nhắn gần nhất của phiên, PHP đọc từ `chat_messages`. Node không giữ trạng thái giữa các lượt, nên không cần checkpointer. **[Claude chọn]** Với tin nhắn của assistant, PHP nối thêm vào `content` một dòng `[Thẻ đã hiện: mã 73 Quần Kaki Ống Rộng; mã 71 …]`, lấy từ `chat_messages.metadata.products`. Câu hỏi của `ask_alternatives` cũng lưu id các gợi ý vào metadata theo cách này. Nhờ đó "cái thứ 2" hay "ống rộng đi" vẫn tra được mã. Body có thêm `memory` (§5.5) và `freeship_fingerprint` (§6).
- **Phản hồi**: luồng NDJSON gồm các sự kiện sau.
  - `{"type":"delta","text":"…"}`
  - `{"type":"cards","products":[…]}`
  - `{"type":"complete","response_type":"final_answer|clarification|refusal|busy","tools_used":[…],"outfits":[…],"trace_id":"…","latency":{…}}`
- Với `stream=false` (dùng cho `POST /api/chatbot` dự phòng), Node trả một JSON gộp các trường trên.
- PHP chuyển `delta` thành `chat.delta` và `cards` thành `chat.cards` (sau khi lọc lại bằng `ChatStreamProtocol::cards`). `complete` thành `chat.complete`. Vì không còn intent, `primary_intent` lấy từ tool chính, theo bảng ánh xạ do builder định.
- **Các trường phản hồi được giữ [Claude chọn]**: widget (`templates/partials/chatbox.php`) chỉ đọc `cards`/`products` và `session_token`. Vì vậy `chat.complete` và JSON của `POST /api/chatbot` chỉ giữ `message`/`answer`, `products`/`cards`, `session_token`, `session_id`, `response_type`, `primary_intent`, `outfits`, `freeship_nudge`, `trace_id` và `latency`. Bỏ `secondary_intents`, `requested_fields`, `missing_slots`, `knowledge_sources`, `freeship_status`. Test PHP và `scripts/dev/web_snapshot.php` được sửa theo.
- **Chọn thẻ [Claude chọn]**:
  - Thẻ là các sản phẩm mà câu trả lời cuối nhắc tới theo dạng "mã N", theo thứ tự xuất hiện, tối đa 6.
  - Câu trả lời không nhắc mã nào thì lấy kết quả của lần gọi tool sản phẩm cuối cùng, theo thứ tự tool trả về, tối đa 6.
  - Với `build_outfit`, trường `outfits` lấy nguyên từ kết quả tool, và thẻ là các món trong những set đó (bỏ trùng).
  - Mọi thẻ đều qua `CardPolicy`.

### 5.5 Memory **[người dùng: giữ cả 4 loại; Claude đề xuất 3 điều kiện, người dùng đồng ý]**

| Loại | Lưu ở | Ai ghi | Gửi sang Node |
|---|---|---|---|
| Lịch sử chat + mã thẻ đã hiện | `chat_messages` (+ `metadata.products`) | PHP | `history`: 6 tin gần nhất (§5.3) |
| Slot phiên | `chat_session_memory.slots` | PHP (`extractSlots`) | `memory.slots`: **chỉ** `size`, `height_cm`, `weight_kg`, `budget`/`min_price`/`max_price`, `gender` (giá trị khác null). Không gửi `product_type`, `color`, `category_id`, `style`, `occasion`, `material` |
| Tóm tắt phiên | `chat_session_memory.summary` | PHP (`generateSummary`) | `memory.summary` |
| Hồ sơ dài hạn (chỉ user đăng nhập) | `user_long_term_memory` | PHP (`mergeLongTerm`, lịch sử mua từ đơn) | `memory.profile`, chỉ theo danh sách trắng: `stable_facts.usual_size`, `preferences.favorite_color`/`favorite_style`/`favorite_texture`, `preferences.avoid_material`/`avoid_style`, tên ngắn 5 món mua gần nhất. Khách vãng lai: `null` |

Điều kiện:

1. **Memory chỉ là gợi ý.** Node đưa `memory` vào prompt dưới mục "Thông tin đã biết về khách (có thể đã cũ)". System prompt ghi rõ: câu hiện tại của khách luôn được ưu tiên hơn memory.
2. **Không gửi dữ liệu nhạy cảm ra nhà cung cấp LLM.** `pregnant`, `skin_type`, `skin_tone`, `body_shape`, `important_events`, `feedback`, `avoid_ingredient`, `favorite_brand`, `favorite_country` không bao giờ rời PHP. Danh sách trắng nằm ở PHP, phía client gọi `chat-graph`, và có unit test riêng: mỗi trường bị cấm phải vắng mặt trong body.
3. **PHP ghi, Node chỉ đọc.** Mỗi lượt PHP vẫn chạy `rememberUserMessage` trước khi gọi Node, như `TurnRunner::loadMemory` đang làm. Không có tool nào cho LLM ghi memory, bộ tool vẫn hoàn toàn chỉ-đọc. `ChatbotMemory` + `ChatMemoryRepository` được giữ. Ở commit xoá (§10), `ChatbotMemory` chuyển sang `src/service/chat/` (đúng tầng service), không bị xoá cùng `src/harness/`. `freeship_fingerprint` vẫn là một khoá trong `slots` như cũ.

### 5.4 Ranh giới streaming **[Claude chọn]**

Luật cũ: chữ chỉ được gửi tới trình duyệt **sau khi** câu trả lời đã qua kiểm tra. Spec giữ luật này.

- Câu trả lời cuối của LLM được gom đủ, kiểm tra ở `policy_out`, rồi mới gửi đi dưới dạng `delta` theo từng đoạn.
- Cái giá phải trả là khách thấy chữ chậm hơn so với stream token trực tiếp.
- Các lượt tool vẫn gửi `chat.progress` để widget hiện "đang tìm…".

## 6. Lớp policy (middleware trong `chat-graph`)

Mỗi luật cũ đều có chỗ đặt mới, kèm test riêng trong CI (§9.1).

| Luật cũ (CLAUDE.md, Hard rules) | Middleware | Cách làm |
|---|---|---|
| Không thêm giỏ, checkout, thanh toán | `CheckoutGuardrail` (beforeAgent) | Port nguyên regex `CheckoutDetector::matches`. Nếu khớp thì trả câu từ chối cố định (lấy từ `ResponseGenerator::checkoutRefusal`), `response_type=refusal`, không gọi LLM hay tool. System prompt cũng nói rõ shop không đặt hàng hộ. |
| Chỉ chạy tool trong allow-list | `ToolAllowList` (wrapToolCall) | Chỉ 9 tên tool trong §8.1 được chạy, tên khác bị từ chối. PHP vẫn chặn lần hai ở `AgentFactory::executeTool` (`ToolDefinitionCatalog`). |
| Thẻ sản phẩm chỉ đến từ catalog và phải qua kiểm tra | `CardPolicy` (afterAgent / `policy_out`) | Thẻ chỉ lấy từ kết quả tool **trong lượt đó**, mọi trường lấy từ kết quả tool chứ không lấy từ chữ LLM viết. Mỗi thẻ được kiểm tra lại theo tham số của lần gọi đã trả ra nó: `in_stock`, `min_price`/`max_price`, `category_id`. Thẻ không đạt bị bỏ. Đây là bản port các phép kiểm tra trong `ProductConstraintVerifier`. |
| Câu trả lời chỉ dựa trên bằng chứng | `GroundedAnswer` (`policy_out`) | Mọi mã sản phẩm ("mã N") và mọi giá ("N.000đ") trong câu trả lời phải có trong kết quả tool của lượt đó. Sai thì gọi LLM lại 1 lần kèm danh sách lỗi. Vẫn sai thì thay bằng câu tóm tắt dựng từ các thẻ. **[Claude chọn]** |
| Giới hạn ngân sách vòng lặp (`MAX_TOOL_CALLS_TOTAL=4`) | `Budget` (beforeModel / wrapToolCall) | Mỗi lượt tối đa **4 lần gọi tool** và **5 lần gọi model**, cùng **45 giây** tổng. Mỗi lần gọi model vẫn theo `LLM_TIMEOUT`. Vượt giới hạn thì kết thúc bằng câu trả lời dựa trên những gì đã có, hoặc câu "hệ thống đang bận" nếu chưa có gì. Các số này nằm trong env của `chat-graph` (`CHAT_GRAPH_MAX_TOOL_CALLS`, `CHAT_GRAPH_MAX_MODEL_CALLS`, `CHAT_GRAPH_TURN_TIMEOUT_MS`) để chỉnh được sau khi chạy eval thật. **[Claude chọn số]** |
| Không có admin env mua hàng, CSRF… | không cần đổi | Thuộc web/REST PHP, không nằm trong luồng chat. |

`FreeShippingNudge` và `TraceOutbox`:
- **[đề xuất, người dùng đồng ý]** Câu nhắc freeship chuyển thành middleware `afterAgent` ở Node.
  - Middleware này gọi `suggest_freeship_items` khi lượt đó có dùng tool mua sắm (`search_products`, `get_product_detail`, `build_outfit`, `find_similar_in_stock`, `get_order_status`).
  - Luật "mỗi trạng thái giỏ chỉ nhắc một lần" vẫn giữ. Dấu vân tay giỏ (cart fingerprint) đang lưu trong `chat_session_memory.slots`; PHP gửi giá trị này sang trong body và nhận giá trị mới trong `complete`. **[Claude chọn cách truyền]**
  - **[Claude chọn]** `chat-graph` đọc `FREE_SHIPPING_THRESHOLD` và `FREESHIP_NUDGE_MAX_GAP` từ env của nó (compose truyền cùng giá trị cho `app` và `chat-graph`).
  - Câu nhắc được dựng bằng mẫu câu từ dữ liệu tool, ghép vào **sau** `policy_out`, nên không cần qua `GroundedAnswer`.
  - Không nhắc ở các lượt `clarification` (gồm cả nhánh hết hàng), `refusal` hay `busy`. Đây là cách luồng cũ đang làm.
- **[đề xuất, người dùng đồng ý]** Trace chuyển sang callback Langfuse của LangChain.js. Callback chỉ bật khi có `LANGFUSE_*`; thiếu cấu hình thì bỏ qua và không làm hỏng lượt chat. Bảng outbox PHP không còn dùng cho chat.

## 7. Khi LLM hoặc `chat-graph` lỗi **[người dùng]**

- Câu trả lời cho khách đúng một câu: **"Hệ thống đang bận, bạn thử lại sau ít phút nhé."** **[Claude chọn câu chữ]**, `response_type=busy`.
- Áp dụng khi: LLM lỗi hoặc quá thời gian chờ, `chat-graph` không phản hồi, PHP gọi `/turn` quá **50 giây** (dài hơn ngân sách 45 giây của graph), hoặc tool lỗi đến mức không còn gì để trả lời.
- Không lộ chi tiết lỗi. Phía server ghi log `error_category`.
- Lượt bị lỗi vẫn lưu tin nhắn của khách.
- Không có đường dự phòng bằng luồng PHP cũ, vì luồng cũ đã bị xoá (§10).

## 8. Thay đổi phía PHP

### 8.1 Tool

- **`search_products`**: trả thêm `out_of_stock_ids: int[]`, khác rỗng trong hai trường hợp:
  - Có `in_stock=true` mà không còn mẫu nào: là id các mẫu khớp mọi điều kiện khác nhưng đã hết hàng (mở rộng PR #23).
  - Không có `in_stock` mà mọi mẫu trả về đều hết hàng: là id các mẫu đó.

  Mảng xếp theo thứ tự xếp hạng của lần tìm. `out_of_stock_matches` bằng độ dài mảng.
- **Tool mới `find_similar_in_stock`** (chỉ-đọc). Bộ tool thành 9:
  - Đầu vào: `product_id` (bắt buộc) và `limit` (1–5, mặc định 2).
  - Trả về: các sản phẩm **còn hàng**, **cùng `category_id`** với mẫu gốc, khác mẫu gốc, có ít nhất 1 `style_tags` trùng.
  - Thứ tự: số tag trùng giảm dần, rồi chênh lệch giá tăng dần, rồi id tăng dần. **[Claude chọn thứ tự phụ]**
  - Mỗi sản phẩm có cùng các trường như kết quả `search_products`.
  - Ví dụ trên `shop_test`, mẫu gốc #69 (streetstyle, dichoi): ra #73 Quần Kaki Ống Rộng và #71 Quần Bò Rách Gối (trùng 2 tag), sau đó tới những mẫu trùng 1 tag. Kết quả thật phụ thuộc tồn kho.
  - Đăng ký ở `ToolDefinitionCatalog`, `ToolApplicationService`, `mcp-server/src/tools.ts`. Viết `ProductService` + `ProductRepository` theo luật tầng (SQL chỉ ở repository).
- `contract.test.ts` kiểm tra đúng 9 tên tool, tất cả `readOnlyHint`.

### 8.2 Controller chat

- `Chatbot/stream.php`, `Chatbot/index.php`: giữ nguyên các bước kiểm tra origin, độ dài và mở phiên (`ChatbotSessionContext`). Thay lời gọi `ChatbotService::respond*` bằng một client HTTP gọi `chat-graph`.
- Lưu tin nhắn user và assistant vào `chat_messages` như cũ.
- `GET /api/chatbot/history` không đổi.
- Client đặt ở `src/agent/` vì đây là chỗ duy nhất được đọc env chat: `CHAT_GRAPH_URL`, `CHAT_GRAPH_TOKEN`. Builder có thể giữ tên `ChatbotService` làm facade.

### 8.3 Env

- `LLM_*` giờ do `chat-graph` đọc.
- `chat-graph` dùng `ChatOpenAI` với `configuration.baseURL = LLM_BASE_URL`, `model = LLM_MODEL`, timeout `LLM_TIMEOUT`.
- `LLM_PROVIDER` chỉ còn hỗ trợ kiểu OpenAI-compatible. Model phải gọi được function/tool calling.
- PHP không đọc `LLM_*` nữa.

## 9. Kiểm thử và eval **[đề xuất, người dùng đồng ý; bỏ eval cũ: người dùng]**

### 9.1 Lớp CI: LLM giả, phải đạt 100%

- Test trong `chat-graph/tests/`, chạy bằng `node --test` như `mcp-server`.
- LLM giả trả các tool call và câu trả lời theo kịch bản soạn trước. Tool cũng giả, trả JSON mẫu. Không cần DB, mạng hay LLM thật.
- Bắt buộc có:
  - Mỗi middleware ở §6 có ca đạt và ca bị chặn.
  - Cạnh "hết hàng": có 2 gợi ý, có 1 gợi ý, không có gợi ý.
  - Vượt giới hạn tool và model.
  - LLM lỗi hoặc quá thời gian chờ dẫn tới câu "hệ thống đang bận".
  - Câu trả lời bịa mã hoặc giá: gọi lại 1 lần, rồi dùng câu tóm tắt.
  - Thẻ không qua `CardPolicy` bị bỏ.
- Job CI "Agent eval" cũ (`php scripts/run_agent_eval.php`) được thay bằng job chạy bộ test này và build `chat-graph`.
- Phía PHP: unit/integration test cho `find_similar_in_stock`, cho `out_of_stock_ids`, và cho controller gọi `chat-graph` (dùng HTTP giả: thành công, quá thời gian chờ, lỗi 5xx).

### 9.2 Lớp chạy tay: LLM thật, chấm theo tỉ lệ

- `chat-graph/eval/cases.jsonl` là bộ ca mới. Chạy end-to-end qua `/turn` với LLM thật và DB `shop_test`.
- Bao gồm:
  - Năm câu ở §1. Ví dụ "quần baggy còn hàng" phải đi qua cạnh hết hàng và nhắc đúng #69.
  - Các nhóm của bộ cũ: outfit, freeship, guardrail, ngoài phạm vi.
  - Câu có nhiều ý.
- Ngưỡng **≥ 90%** ca đạt. **[Claude chọn số]** Báo cáo ghi vào `reports/eval/` (gitignored).
- Không chặn CI. Các ca guardrail (checkout) chạy được không cần LLM nên đưa luôn vào lớp CI.
- Xoá `eval/`, `scripts/run_agent_eval.php` và `.claude/rules/eval.md` cũ. Viết lại `eval.md` cho bộ mới.

## 10. Xoá và cập nhật

- **Xoá**:
  - Các component của luồng cũ trong `src/harness/`: `intent/`, `planning/`, `evidence/`, `loop/`, `response/`, `llm/`, `middleware/`, `turn/`, phần `memory/` không còn dùng (`ChatbotMemory` không bị xoá, xem §5.5).
  - `src/llm_providers/`.
  - Phần của `AgentFactory`/`HarnessConfig` chỉ phục vụ luồng cũ.
  - Test tương ứng.
- **Giữ**:
  - `src/harness/tools/` (`ToolApplicationService`, `ToolDefinitionCatalog`).
  - `AgentFactory::executeTool` hoặc bản kế nhiệm nó cho `/api/internal/mcp`.
  - Lưu tin nhắn và phiên chat.
  - Cả 4 loại memory (§5.5): `ChatbotMemory` chuyển sang `src/service/chat/`, `ChatMemoryRepository` giữ nguyên.
- **Cập nhật**:
  - `ArchitectureTest` và `src/harness/README.md` theo cấu trúc mới.
  - `CLAUDE.md`: thay câu "The LLM never writes SQL and never picks tools" bằng mô tả mới. LLM chọn tool trong allow-list chỉ-đọc; PHP vẫn là nơi duy nhất có SQL; policy middleware giữ các luật cứng.
  - `.claude/rules/architecture.md`, `testing.md`, `deployment.md`: thêm service `chat-graph`.
  - `.github/workflows/ci.yml`: thêm build/test `chat-graph` và build image.
- **[người dùng]** Việc xoá là **một commit riêng, ở cuối nhánh**, và chỉ làm **sau khi** eval với LLM thật (§9.2) đạt ≥ 90%. Trước commit đó:
  - Luồng cũ vẫn nằm trong code nhưng controller không gọi tới nữa.
  - Test của luồng cũ vẫn chạy cho tới khi xoá.
  - Job eval PHP cũ trong CI được thay bằng job của `chat-graph` (§9.1).
- Nếu endpoint LLM chưa hoạt động thì nhánh dừng trước commit xoá và chờ.

## 11. Phần để builder tự quyết

- Bố cục thư mục `chat-graph/` và cách dùng chung code với `mcp-server` (workspace npm hay import đường dẫn tương đối).
- Phiên bản chính xác của `langchain`, `@langchain/langgraph`, `@langchain/openai`, `@langchain/mcp-adapters` (phải ≥ v1 và ghim chính xác). Cơ chế LangGraph cụ thể để dựng cạnh "hết hàng": middleware `jumpTo`, `Command` từ tool hay graph ngoài bọc agent. Builder phải kiểm tra lại với tài liệu LangChain.js v1, không đoán API.
- Nội dung system prompt, miễn là nó nêu rõ: chỉ dùng tool của shop, không đặt hàng hộ, trả lời tiếng Việt, nhắc mã sản phẩm theo dạng "mã N".
- Bảng ánh xạ từ tool sang `primary_intent` cho `chat.complete`.
- Cách chia `delta` thành từng đoạn khi gửi.
