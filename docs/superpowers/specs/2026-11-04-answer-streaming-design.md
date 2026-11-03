# Spec: Stream câu trả lời cuối của chatbot theo từng câu
- Ngày: 2026-11-04
- Trạng thái: ĐÃ DUYỆT (2026-11-04)
- Nền: `chat-graph` (spec `2026-11-01-langgraph-chat-agent-design.md`), ticket hỗ trợ (spec `2026-11-03-support-ticket-design.md`). Làm trên một nhánh mới tách từ `develop`.

Ký hiệu: **[người dùng]** là quyết định của người dùng. **[đề xuất, người dùng đồng ý]** là đề xuất của Claude đã được người dùng chấp nhận. **[Claude chọn]** là lựa chọn của Claude, người dùng chưa duyệt riêng.

## 1. Bối cảnh và lý do

Đường truyền từ trình duyệt tới PHP đã là SSE (`POST /api/chatbot/stream`), PHP đọc NDJSON từ `chat-graph` và chuyển tiếp ngay. Nhưng `chat-graph` chỉ phát chữ sau khi cả graph chạy xong (`chat-graph/src/turn.ts`: `sentenceChunks(outcome.message)` sau `graph.invoke`). Khách chờ 10-45 s rồi thấy cả đoạn hiện ra cùng lúc. Đo ngày 2026-11-04 trên dev (model cũ `gpt-oss-120b-sale`): "phí ship bao nhiêu" chữ đầu tiên ở 11,6 s, "tìm áo hoodie dưới 400k" ở 46 s, và mọi `delta` đến trong cùng 1-3 ms.

**[người dùng]** Stream câu trả lời cuối ra frontend qua SSE, để chữ hiện dần trong lúc model đang viết. **Chỉ stream câu trả lời cuối, không stream tiến trình** (không có dòng "Đang tìm sản phẩm…", các sự kiện `progress` của `chat-graph` tiếp tục bị PHP bỏ như hiện nay).

Đo nhà cung cấp với model hiện tại `deepseek-v4-flash-sale` (**[người dùng]** đổi ngày 2026-11-04), gọi thẳng `/chat/completions` với `stream: true`:

| Lần gọi | Token suy luận đầu | Chữ đầu tiên | Xong | Ghi chú |
| --- | --- | --- | --- | --- |
| Trả lời 475 ký tự, không tool | 3,8 s | 8,1 s | 9,9 s | 47 mẩu chữ |
| Có tool, khách hỏi "phí ship bao nhiêu" | (không có) | 1,2 s | 2,0 s | **viết 60 ký tự chữ dẫn rồi mới gọi tool**, tool call cũng được stream |

Kết luận cho thiết kế:
- Nhà cung cấp hỗ trợ stream, cả khi có tool. Model suy luận trước (phần `reasoning_content`, không hiện cho khách), nên phần lớn thời gian chờ vẫn còn. Với lần gọi trên, chữ đầu tiên đến sớm hơn khoảng 1,8 s so với chờ cả câu trả lời; câu trả lời càng dài thì lợi càng nhiều. Đây là cải thiện cảm nhận, không làm tổng thời gian ngắn đi.
- Model này có thể viết một câu dẫn ("Để mình kiểm tra chính sách ship cho bạn nhé.") rồi mới gọi tool. Câu đó không phải câu trả lời cuối và không được hiện cho khách (§4.2).

## 2. Mục tiêu, ngoài phạm vi, điều không được xảy ra

Mục tiêu:
- Ở lượt bình thường, chữ của câu trả lời cuối hiện ra theo từng câu ngay khi model viết xong câu đó.
- Mọi chữ khách thấy đều đã qua kiểm tra như hiện nay. Nội dung lưu vào lịch sử chat đúng bằng nội dung khách thấy khi lượt kết thúc.

Ngoài phạm vi:
- Stream tiến trình hay tên tool (**[người dùng]**).
- Giảm thời gian suy luận của model, đổi model, đổi prompt.
- Endpoint JSON `POST /api/chatbot` (fallback của widget) và eval: giữ nguyên, vẫn trả cả câu trả lời một lần.
- Stream từng token bên trong một câu (đơn vị nhỏ nhất là một câu hoặc một dòng, §4.3).

Điều không được xảy ra:
- Khách thấy một giá, một "mã N" hay một con số tồn kho mà kết quả tool của lượt đó không chứng minh được.
- Khách thấy markup gọi tool, markdown thô, hay câu hỏi xác nhận gửi yêu cầu khi chưa có bản nháp ticket hợp lệ.
- Khách thấy câu dẫn của một lần gọi model mà sau đó gọi tool.
- Lịch sử chat lưu một câu trả lời khác với câu trả lời khách thấy sau cùng.
- Một lượt bị lỗi giữa chừng làm hỏng câu trả lời "Hệ thống đang bận" như hiện nay.

## 3. Hiện trạng liên quan

- `chat-graph/src/graph/buildGraph.ts`: node `agent` chạy `createAgent` (vòng model <-> tool, middleware `policyMiddleware`), rồi `policy_out` kiểm tra cả câu trả lời: markup, `ungroundedClaims` (giá và "mã N" phải có trong kết quả tool), câu trả lời hết hàng, `plainText`, bỏ câu hỏi xác nhận ticket không có nháp, nháp ticket hợp lệ thì thay bằng dòng tóm tắt cố định + câu hỏi cố định. Có thể gọi model lại một lần (retry) khi câu trả lời có vấn đề. Sau đó `ask_alternatives` (lượt hết hàng) hoặc `nudge` (gợi ý freeship) có thể nối thêm chữ cố định.
- `chat-graph/src/graph/middleware.ts`: `afterModel` có thể đưa vòng agent quay lại model một lần (câu trả lời rỗng, thiếu tin hết hàng, hỏi xác nhận ticket khi chưa có nháp). `wrapModelCall` là nơi mọi lần gọi model của vòng agent đi qua.
- `chat-graph/src/turn.ts`: phát `delta` (theo câu), `cards`, `complete` sau khi graph xong. `chat-graph/src/contract.ts`: `TurnEvent`.
- `src/agent/ChatGraphClient.php`: đọc NDJSON, cộng dồn `delta` vào `message`, gọi `$onEvent` cho từng sự kiện. `src/agent/ChatbotService.php`: chỉ chuyển `delta` cho `stream.php`, lưu `$result['message']`.
- `templates/partials/chatbox.php`: `handleChatStreamEvent` cộng dồn `chat.delta` vào bong bóng chat (`textContent`).
- `src/app/api/support/ChatStreamProtocol.php`: khung SSE và danh sách trường card.

## 4. Thiết kế

### 4.1 Khi nào được stream

**[Claude chọn]** Một lượt chỉ stream khi `req.stream === true` và đủ cả ba điều kiện tại thời điểm model bắt đầu viết câu trả lời:
1. Không có lượt hết hàng (`recorder.soldOut === null`). Lượt hết hàng có thể bị bắt viết lại và luôn được nối câu hỏi cố định, nên gửi một lần như hiện nay.
2. Không có nháp ticket hợp lệ (`lastValidDraft(recorder.records) === null`). Lượt có nháp thì câu trả lời được thay bằng dòng tóm tắt cố định, nên gửi một lần như hiện nay.
3. Lần gọi model thuộc vòng agent (đi qua `wrapModelCall`). Lần retry trong `policy_out` không stream.

Lượt không thoả thì giữ đúng hành vi hiện nay (phát theo câu sau khi graph xong). Lượt từ chối thanh toán (`guard`) không gọi model nên cũng như hiện nay.

### 4.2 Chỉ stream câu trả lời cuối, không stream câu dẫn

Không biết trước một lần gọi model có kết thúc bằng tool call hay không. **[Claude chọn]** Trong mỗi lần gọi model:
- Ngay khi thấy một mẩu tool call (`tool_call_chunks`), lần gọi đó không phát thêm gì nữa và phần chữ còn giữ bị bỏ.
- Câu hoàn chỉnh đầu tiên của lần gọi được **giữ lại** cho tới khi câu thứ hai hoàn chỉnh hoặc lần gọi kết thúc không có tool call. Câu dẫn trước tool call thường chỉ một câu rồi đến tool call ngay, nên bị bỏ mà khách không thấy. Cái giá là câu đầu của câu trả lời thật hiện ra trễ hơn một câu.
- Nếu tool call đến sau khi đã phát từ hai câu trở lên (hiếm), các câu đã phát được xử lý như §4.5 (thay toàn bộ ở cuối lượt).

### 4.3 Mỗi câu được kiểm tra trước khi phát

Đơn vị phát là một câu (kết thúc bằng `.`, `?`, `!` rồi khoảng trắng, như `sentenceChunks`) hoặc một dòng (`\n`), lấy cái đến trước. Một dấu chấm giữa hai chữ số (`490.000đ`) không kết thúc câu. Trước khi phát, mỗi đơn vị phải qua, theo đúng hàm hiện có:
- `hasToolCallMarkup` → có thì dừng stream.
- `ungroundedClaims(câu, bằng chứng)` với bằng chứng là `recorder.records` lúc đó (giống `policy_out` khi không có hết hàng) → có thì dừng stream.
- `asksTicketConfirmation` → có thì dừng stream (`afterModel` sẽ quay lại model, hoặc `policy_out` sẽ bỏ câu đó).
- `plainText` → phát bản đã bỏ markdown.

"Dừng stream" nghĩa là lượt đó không phát thêm chữ nào qua đường stream; phần còn lại đi theo đường cũ (§4.5). Bộ kiểm tra cả câu trả lời trong `policy_out` vẫn chạy y như hiện nay và vẫn là nơi quyết định cuối cùng.

### 4.4 Sự kiện mới và nối với phần đã phát

**[Claude chọn]** Thêm một sự kiện NDJSON vào `TurnEvent`: `{ type: 'replace', text: string }`, nghĩa là "toàn bộ chữ đã phát của lượt này được thay bằng `text`".

Cuối lượt (`turn.ts`), sau khi graph xong và có `outcome.message`, gọi `S` là chuỗi đã phát qua stream (nối các câu):
- `S` rỗng: phát như hiện nay (`delta` theo câu).
- `outcome.message` bắt đầu bằng `S`: phát phần còn lại dưới dạng `delta` (ví dụ dòng gợi ý freeship do `nudge` nối thêm, hay câu cuối không có dấu kết câu).
- Khác (retry, câu xác nhận bị bỏ, `plainText` cả đoạn khác với từng câu, tool call đến muộn): phát một `replace` với `outcome.message`.

Sau đó `cards` và `complete` như hiện nay. Lỗi giữa chừng (model lỗi, quá hạn): `outcome` là busy, nên khách thấy `replace` bằng câu "Hệ thống đang bận…" nếu đã có chữ được phát.

### 4.5 PHP và widget

- `ChatGraphClient`: thêm `replace` vào `EVENT_TYPES`; `replace` đặt `message = text` (không cộng dồn). Câu trả lời trả về và lưu vào lịch sử vẫn là `message` sau cùng.
- `ChatbotService`: chuyển `replace` ra trình duyệt cùng `delta`. **[Claude chọn]** Đổi tham số `$onDelta` thành nhận cả loại sự kiện (`delta` hoặc `replace`), không thêm callback thứ hai. Nhánh `TICKET_FAILED` hiện nối thêm một `delta`; giữ nguyên vì nó chỉ xảy ra ở lượt có nháp (không stream).
- `stream.php` và `ChatStreamProtocol`: thêm sự kiện SSE `chat.replace` với trường `text`, cùng khung với `chat.delta`.
- `chatbox.php`: `chat.replace` đặt `active.text = text` rồi vẽ lại bong bóng như `chat.delta`. Không thêm hiệu ứng gõ chữ.
- Sự kiện `progress` tiếp tục không được chuyển ra trình duyệt (**[người dùng]**). Widget giữ chấm "đang gõ" cho tới chữ đầu tiên như hiện nay.

### 4.6 Model và kết nối

- `createChatModel` (`chat-graph/src/model.ts`) bật `streaming: true` để các lần gọi trong vòng agent nhận token dần. Cách lấy token (callback `handleLLMNewToken` chỉ cho các lần gọi đi qua `wrapModelCall`, hay gọi `model.stream` trong `wrapModelCall`) là lựa chọn của người làm, miễn là: lần retry của `policy_out` không phát; `budget`, `afterModel`, `answerOnly` và hạn chót của lượt giữ nguyên hành vi; Langfuse vẫn nhận đủ trace.
- `/turn` với `stream: false` (endpoint JSON, eval) không phát gì cho tới cuối, như hiện nay.

## 5. Kiểm thử

Theo `.claude/rules/testing.md`, thay đổi trên đường chat phải có test node của `chat-graph` (model giả lập, không mạng) và test PHP:
- `chat-graph`: model giả lập phát token từng mẩu. Các ca: lượt bình thường phát câu trước khi graph xong (thứ tự sự kiện: `delta` đầu tiên đến trước khi model giả lập viết xong); câu dẫn rồi tool call thì câu dẫn không bao giờ được phát; câu có giá không có trong tool thì dừng stream và cuối lượt có `replace` khớp `policy_out`; câu hỏi xác nhận ticket không có nháp không bao giờ được phát; lượt hết hàng và lượt có nháp ticket không stream (giống hệt hiện nay); `nudge` nối thêm thành `delta` cuối; lỗi model giữa chừng cho `replace` bằng câu busy; `stream: false` không phát gì trước `complete`. Bất biến cho mọi ca: nối các `delta` (đặt lại khi gặp `replace`) bằng đúng `message` của kết quả.
- PHP: `ChatGraphClient` với `replace` (đặt lại `message`), `ChatbotService` chuyển `replace` và lưu câu trả lời cuối, `ChatStreamProtocol` khung `chat.replace`.
- Widget: thử bằng trình duyệt (puppeteer như các lần trước) một lượt hỏi chính sách dài, chữ phải hiện dần; một lượt hết hàng vẫn đúng như hiện nay.
- Eval thật (`npm --prefix chat-graph run eval`) đi qua endpoint JSON nên không đo stream; chạy lại để chắc không hỏng gì (mục tiêu >= 90%), và đo thời gian chữ đầu tiên trên dev trước và sau.

## 6. Tài liệu cần cập nhật

- `CLAUDE.md` (sơ đồ chatbot: `delta` có thể đến trước khi graph xong), `.claude/rules/architecture.md` (§ Streaming boundary và bước 4 `policy_out`: kiểm tra theo câu khi stream, sự kiện `replace`).
- Spec gốc `2026-11-01-langgraph-chat-agent-design.md`: ghi chú trỏ sang spec này ở chỗ nói chữ chỉ phát sau `policy_out`.

## 7. Quyết định đã chốt (2026-11-04)

1. **[đề xuất, người dùng đồng ý]** Khi lượt phải viết lại sau khi đã phát vài câu, phát `replace` (§4.4): khách thấy đoạn chữ đổi hẳn sang câu trả lời cuối. Không có chế độ "đã phát thì không retry".
2. **[đề xuất, người dùng đồng ý]** Giữ câu đầu của mỗi lần gọi model cho tới khi câu thứ hai xong hoặc lần gọi kết thúc không có tool call (§4.2), chấp nhận câu đầu hiện trễ một nhịp.
3. **[Claude chọn]** Khi PHP không nhận được kết quả từ `chat-graph` sau khi đã chuyển chữ ra trình duyệt (lỗi kết nối giữa chừng), PHP phát `replace` bằng câu "Hệ thống đang bận…" để chữ khách thấy khớp với chữ được lưu (§2).
