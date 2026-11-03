# Spec: Ticket hỗ trợ đơn hàng qua chatbot
- Ngày: 2026-11-03
- Trạng thái: ĐÃ DUYỆT (2026-11-03)
- Nền: chatbot `chat-graph` (spec `2026-11-01-langgraph-chat-agent-design.md`). Làm trên một nhánh mới tách từ `develop` sau khi `feat/langgraph-chat-agent` đã merge, kể cả bản sửa F04 (chặn gọi tool lặp, ép trả lời ở lượt cuối) **[Claude chọn]**. Cạnh xác nhận nằm trong `policy_out` (`chat-graph/src/graph/buildGraph.ts`), như cạnh hết hàng.

Ký hiệu: **[người dùng]** là quyết định của người dùng. **[đề xuất, người dùng đồng ý]** là đề xuất của Claude đã được người dùng chấp nhận. **[Claude chọn]** là lựa chọn của Claude, người dùng chưa duyệt riêng.

## 1. Bối cảnh và lý do

Khách thường cần những việc mà chatbot không được làm: đổi size, đổi trả đơn đã giao, báo hàng lỗi hoặc giao sai, xin huỷ đơn, đổi địa chỉ giao. Hiện bot chỉ trả lời chính sách (`retrieve_knowledge`) rồi bảo khách tự liên hệ shop, nên yêu cầu không được ghi lại ở đâu và admin không biết.

**[người dùng]** Thêm use case "ticket hỗ trợ đơn hàng": bot ghi lại yêu cầu của khách thành một ticket, admin xử lý tay trong trang admin, khách xem lại trạng thái qua bot hoặc trang tài khoản. Bot vẫn không động tới giỏ hàng, đơn hàng hay thanh toán. Ticket chỉ là một bản ghi yêu cầu.

## 2. Mục tiêu, ngoài phạm vi, điều không được xảy ra

Mục tiêu:
- Khách đã đăng nhập tạo được ticket qua chat. Bot luôn hỏi xác nhận trước khi tạo, và code (không phải model) quyết định có tạo hay không.
- Admin xem, lọc, đổi trạng thái và ghi chú trả lời ticket trong trang admin.
- Khách xem trạng thái và ghi chú của admin qua bot và trong trang tài khoản.

Ngoài phạm vi:
- Ảnh đính kèm (§3 điểm 8).
- Gửi email hoặc thông báo đẩy (§3 điểm 5).
- Form tạo ticket trên web (chỉ tạo qua chat).
- Tự động huỷ đơn, đổi địa chỉ hay hoàn tiền. Admin làm tay ở các trang hiện có.
- Hội thoại hai chiều trong ticket (admin chỉ có một ghi chú trả lời, ghi đè được).

Điều không được xảy ra:
- Ticket được tạo mà khách chưa xác nhận, hoặc model tự "xác nhận" thay khách.
- Khách xem hoặc tạo ticket gắn với đơn của người khác.
- Bot tự nhận đã huỷ đơn, đã đổi hàng hay đã hoàn tiền.
- LLM có trong tay một tool ghi dữ liệu.

## 3. Các quyết định đã chốt (người dùng đồng ý cả 8, 2026-11-03)

1. **[đề xuất, người dùng đồng ý]** Loại yêu cầu: `doi_tra` (đổi size hoặc đổi trả), `hang_loi` (hàng lỗi hoặc giao sai), `huy_don`, `doi_dia_chi`, `khac`. Nhãn hiển thị: "Đổi size / đổi trả", "Hàng lỗi / giao sai", "Huỷ đơn", "Đổi địa chỉ giao", "Khác".
2. **[đề xuất, người dùng đồng ý]** Chỉ khách đã đăng nhập được tạo ticket; khách chưa đăng nhập được mời đăng nhập. Nếu có mã đơn, PHP kiểm tra đơn đó thuộc về khách; không thuộc thì không tạo được.
   - **[đề xuất, người dùng đồng ý, 2026-11-03]** Mã đơn **bắt buộc** cho `doi_tra`, `hang_loi`, `huy_don`, `doi_dia_chi`; chỉ `khac` được để trống. Thiếu mã thì `draft` trả `order_required`. Bot hỏi lại khách, hoặc lấy đơn gần nhất bằng `get_order_status` rồi hỏi "có phải đơn #N không" trước khi soạn nháp.
3. **[đề xuất, người dùng đồng ý]** Xác nhận bắt buộc. Bot tóm tắt yêu cầu và hỏi "Bạn xác nhận gửi yêu cầu này cho shop không?". Ticket chỉ được tạo khi khách đồng ý ở câu kế tiếp, và việc nhận ra "đồng ý" là của code.
4. **[đề xuất, người dùng đồng ý]** Trạng thái: `moi` (Mới) → `dang_xu_ly` (Đang xử lý) → `da_xong` (Đã xong) hoặc `tu_choi` (Từ chối), kèm ghi chú trả lời của admin.
5. **[đề xuất, người dùng đồng ý]** Khách hỏi bot ("yêu cầu của tôi sao rồi") thì bot đọc trạng thái và ghi chú. Trang tài khoản có thêm mục "Yêu cầu hỗ trợ". Không gửi email.
6. **[đề xuất, người dùng đồng ý]** Trang admin: danh sách ticket lọc theo trạng thái; trang chi tiết có link tới đơn; đổi trạng thái và ghi chú qua POST có CSRF.
7. **[đề xuất, người dùng đồng ý]** Chống spam: mỗi lượt chat tạo tối đa 1 ticket. Không tạo trùng khi đã có ticket đang mở (`moi` hoặc `dang_xu_ly`) cùng đơn và cùng loại. Luật trùng chỉ áp dụng khi có mã đơn, nên ticket `khac` không có đơn chỉ bị giới hạn bởi mức 5 **[người dùng đồng ý, 2026-11-03]**. Mỗi khách tối đa 5 ticket đang mở.
8. **[đề xuất, người dùng đồng ý]** Chưa có ảnh đính kèm, ticket chỉ có chữ.

## 4. Kịch bản

**A. Giao sai size (luồng chính).** Khách đã đăng nhập, có đơn #12.
1. Khách: "đơn 12 mình đặt áo size L mà giao size M, đổi giúp mình". Model gọi `get_order_status` (`order_id: 12`), rồi `draft_support_ticket` (`type: doi_tra`, `order_id: 12`, `summary: "Đặt áo size L nhưng nhận size M, muốn đổi sang L"`).
2. PHP kiểm tra (đăng nhập, đơn #12 của khách, không trùng, chưa quá 5 ticket mở) và trả về bản nháp hợp lệ. Không ghi gì vào DB.
3. Bot: "Mình sẽ gửi yêu cầu sau cho shop: Đổi size / đổi trả, đơn #12: Đặt áo size L nhưng nhận size M, muốn đổi sang L. Bạn xác nhận gửi yêu cầu này cho shop không?" PHP lưu bản nháp vào memory của phiên chat.
4. Khách: "đồng ý". PHP thấy có bản nháp đang chờ và câu khách là một câu xác nhận, nên tạo ticket #7 (trạng thái `moi`) và trả lời cố định: "Đã gửi yêu cầu #7 cho shop. Shop sẽ xử lý và cập nhật trạng thái; bạn có thể hỏi mình hoặc xem ở trang tài khoản." Lượt này không gọi LLM.
5. Admin mở "Yêu cầu hỗ trợ", thấy #7 ở Mới, chuyển sang Đang xử lý, ghi chú: "Shop sẽ gửi áo size L, bạn gửi lại áo size M cho shipper khi nhận hàng."
6. Hôm sau khách hỏi: "yêu cầu đổi size của mình sao rồi". Model gọi `get_support_tickets`, bot đọc lại trạng thái và ghi chú của admin. Trang tài khoản cũng hiện #7 cùng trạng thái và ghi chú.

**B. Xin huỷ đơn đang giao.** Như A với `type: huy_don`. Admin có thể chuyển sang Từ chối với ghi chú "Đơn đã giao cho đơn vị vận chuyển, không huỷ được". Bot không bao giờ nói "đã huỷ đơn", chỉ nói "đã gửi yêu cầu huỷ".

**C. Khách chưa đăng nhập.** `draft_support_ticket` trả về `requires_login`. Bot mời khách đăng nhập để gửi yêu cầu, không có bản nháp.

**D. Đã có ticket đang mở cùng đơn, cùng loại.** `draft_support_ticket` trả về `duplicate_open` kèm mã ticket cũ. Bot báo khách "Yêu cầu #7 cho đơn #12 đang ở trạng thái Đang xử lý", không hỏi xác nhận, không tạo mới.

**E. Khách không xác nhận.** Ở bước 4 khách nói "thôi khỏi" hoặc hỏi sang chuyện khác. PHP bỏ bản nháp, không tạo ticket. Câu "thôi/không/huỷ" nhận câu trả lời cố định "Mình đã huỷ yêu cầu, chưa gửi gì cho shop."; câu khác đi tiếp vào chat-graph như một lượt bình thường.

**F. Đơn không phải của khách / quá 5 ticket mở.** `draft_support_ticket` trả về `order_not_found` hoặc `too_many_open`. Bot giải thích, không hỏi xác nhận.

**G. Thiếu mã đơn.** Khách: "áo mình nhận bị rách". Model gọi `get_order_status` (không mã), thấy đơn gần nhất #12, rồi hỏi "Có phải đơn #12 không?". Khách xác nhận thì tiếp tục như A, với `type: hang_loi`. Nếu model gọi `draft_support_ticket` mà thiếu mã cho một loại cần đơn, kết quả là `order_required` và bot hỏi mã đơn.

## 5. Thiết kế

### 5.1 Dữ liệu

**[Claude chọn]** Migration mới `database/migrations/2026_11_03_support_tickets.sql`, bảng `support_tickets`:

| Cột | Kiểu | Ghi chú |
| --- | --- | --- |
| `id` | int, khoá chính, tự tăng | mã ticket hiện cho khách (#7) |
| `user_id` | int, NOT NULL, FK `users(id)` | |
| `order_id` | int, NULL, FK `orders(id)` | |
| `type` | varchar(20), NOT NULL | một trong 5 giá trị §3.1 |
| `summary` | varchar(500), NOT NULL | lời tóm tắt do model viết từ câu khách |
| `status` | varchar(20), NOT NULL, mặc định `moi` | một trong 4 giá trị §3.4 |
| `admin_note` | text, NULL | |
| `chat_session_id` | int, NULL | phiên chat tạo ra ticket, để admin tra lại |
| `created_at`, `updated_at` | timestamp | |

Chỉ mục `(user_id, status)` và `(status, created_at)`. SQL chỉ nằm trong repository mới `SupportTicketRepository`.

### 5.2 PHP

- **[Claude chọn]** `src/service/support/SupportTicketService.php` giữ mọi luật nghiệp vụ, gồm loại hợp lệ, đơn thuộc khách, trùng, tối đa 5 ticket mở, `summary` 1–500 ký tự. Các hàm:
  - `draft(userId, args)`: chỉ kiểm tra, không ghi. Trả về `{status: "draft", draft: {type, order_id, summary}}` hoặc `{status: "requires_login" | "order_required" | "order_not_found" | "duplicate_open" (kèm ticket_id, trạng thái) | "too_many_open" | "invalid"}`.
  - `create(userId, draft, sessionId)`: kiểm tra lại toàn bộ, vì giữa hai lượt có thể đã có ticket khác, rồi ghi.
  - `listForUser(userId, ticketId?)`: tối đa 5 ticket mới nhất, hoặc đúng ticket đó nếu là của khách.
  - `adminList(status?)`, `adminDetail(id)`, `adminUpdate(id, status, note)`.
- Hai tool mới trong `ToolDefinitionCatalog` và `ToolApplicationService`: `draft_support_ticket` gọi `draft`, và `get_support_tickets` gọi `listForUser`. Cả hai đều **không ghi DB**. Không có tool `create_*` ở đâu cả.
- **Bước xác nhận nằm trong PHP** (`ChatbotService::turn`) **[Claude chọn]**. Lý do: việc ghi ở lại trong PHP, nơi duy nhất có SQL, và chat-graph cùng LLM không bao giờ có đường ghi.
  - Bản nháp chờ xác nhận lưu trong `chat_session_memory.slots` dưới khoá `pending_ticket` (chuỗi JSON `{type, order_id, summary, expires_at}`), hết hạn sau 15 phút **[Claude chọn]**.
  - Đầu mỗi lượt, nếu có `pending_ticket` còn hạn:
    - câu khách là xác nhận → `create`, trả lời cố định, xoá slot, không gọi chat-graph;
    - câu khách là từ chối → xoá slot, trả lời cố định;
    - còn lại → xoá slot rồi chạy lượt bình thường.
  - `pending_ticket` hết hạn thì xoá. Nếu nháp của chính khách này đã hết hạn mà câu khách là xác nhận, trả lời cố định "yêu cầu soạn trước đó đã hết hạn nên mình chưa gửi gì cho shop, bạn nhắn lại yêu cầu để mình soạn lại"; là từ chối thì trả lời huỷ cố định; còn lại chạy lượt bình thường. Cả hai lượt này không gọi chat-graph và không tạo ticket.
  - Danh sách xác nhận/từ chối chấp nhận thêm "dạ " ở đầu và các từ lịch sự ở cuối (ạ, nhé, nha, đi, bạn, shop...) quanh một cụm cố định; câu có ý khác vẫn là "other".
  - Nhận ra xác nhận: câu khách sau khi bỏ dấu câu, đổi chữ thường và gộp khoảng trắng phải nằm trong một danh sách cố định ngắn, ví dụ "đồng ý", "xác nhận", "ok", "có", "gửi đi", "ừ", "đúng rồi". Câu dài hơn hoặc lẫn ý khác không tính là xác nhận **[Claude chọn]**. Danh sách từ chối cũng làm như vậy.
  - Kết quả lượt xác nhận được lưu vào lịch sử chat như mọi lượt khác, `response_type` là `final_answer`, `tools_used` là `[]`.
- chat-graph đặt bản nháp lên outcome. PHP lưu `pending_ticket` khi outcome của chat-graph có `pending_ticket` (trường mới, xem §5.3). PHP kiểm tra `user_id` của lượt khớp với lượt tạo nháp.
- Trang tài khoản (`ProfilePage`, `templates/pages/profile.php`): thêm mục "Yêu cầu hỗ trợ" gồm mã, loại, đơn, trạng thái, ghi chú admin và ngày tạo.
- Trang admin mới `public/admin/manage_tickets.php` → `src/app/web/admin/AdminTicketsPage.php` → `templates/pages/admin/tickets.php`:
  - Danh sách lọc theo trạng thái, mặc định Mới và Đang xử lý.
  - Chi tiết có link tới đơn ở trang quản lý đơn.
  - Form POST có CSRF (`Csrf.php`) để đổi trạng thái và ghi chú.
  - Có `AdminGuard`.
  - Dashboard admin hiện số ticket Mới.
  - Admin chỉ có quyền xem và đổi trạng thái, không xoá.
- REST: **[Claude chọn]** chưa thêm endpoint REST cho ticket (ngoài phạm vi). Admin và khách dùng trang web, bot dùng tool.

### 5.3 chat-graph

- Allow-list có thêm `draft_support_ticket` và `get_support_tickets`, thành 11 tool, vẫn đều chỉ đọc. `mcp-server/src/tools.ts` khai báo schema của hai tool:
  - `draft_support_ticket`: `type` (enum 5 giá trị), `order_id` (tuỳ chọn), `summary` (1–500 ký tự).
  - `get_support_tickets`: `ticket_id` (tuỳ chọn).
- Prompt thêm hai ý:
  - Khi khách cần đổi trả, báo lỗi, huỷ đơn hay đổi địa chỉ, gọi `draft_support_ticket` sau khi đã nắm được đơn và vấn đề. Không bao giờ nói đã huỷ, đã đổi hay đã hoàn tiền.
  - Khi khách hỏi về yêu cầu đã gửi, gọi `get_support_tickets`.
- **Cạnh xác nhận (code, không phải LLM)**, tương tự cạnh hết hàng: khi lượt có một kết quả `draft_support_ticket` với `status: "draft"` (lấy kết quả cuối nếu model gọi nhiều lần), `policy_out` sẽ:
  - đặt `pending_ticket` trên outcome;
  - đặt `response_type = "clarification"`;
  - luôn nối tóm tắt cố định của bản nháp (loại, đơn, nội dung) và câu hỏi xác nhận cố định "Bạn xác nhận gửi yêu cầu này cho shop không?" (đúng một lần) sau lời dẫn của model; model viết thiếu hay trùng câu hỏi thì code vẫn cho cùng kết quả.

  Lượt có bản nháp không gắn thẻ sản phẩm.
- **Chốt chặn câu xác nhận tự viết (code)**: khi model viết câu hỏi xác nhận gửi của riêng nó (câu kết thúc bằng "?" chứa "xác nhận" hoặc "đồng ý" cùng "gửi" hoặc "tạo") mà lượt không có bản nháp hợp lệ (và không phải lượt hết hàng), `afterModel` cho model thêm đúng một bước có tool kèm ghi chú gọi `draft_support_ticket` (trong ngân sách như bước tiếp tục hiện có); nếu vẫn vậy, `policy_out` xoá các câu đó, hoặc trả câu cố định `TICKET_NEED_DETAILS` ("Bạn cho mình biết mã đơn và vấn đề cụ thể để mình soạn yêu cầu gửi shop nhé.") khi không còn gì có nghĩa; không đặt `pending_ticket`. Lý do: chỉ hệ thống mới được hỏi xác nhận, và chỉ sau khi có bản nháp thật để PHP giữ.
- Grounding: mã ticket ("yêu cầu #7") và mã đơn trong kết quả hai tool mới được coi là có thật, như mã đơn của `get_order_status`.
- Luật cứng hiện có giữ nguyên. Nếu câu trả lời tự nhận đã huỷ, đã đổi hay đã hoàn tiền thì thuộc chốt chặn đầu ra của PR khác; spec này không thêm luật từ khoá.

### 5.4 Hợp đồng PHP ↔ chat-graph

- Request `/turn` không đổi (bản nháp không cần gửi sang, vì PHP xử lý lượt xác nhận trước khi gọi chat-graph).
- Outcome (`complete`) có thêm `pending_ticket: {type, order_id, summary} | null`. PHP chỉ lưu khi `user_id` khác null.

## 6. Luật cứng cần sửa trong tài liệu

- CLAUDE.md và `.claude/rules/architecture.md`:
  - "9 read-only tools" thành 11.
  - Thêm: "Ticket hỗ trợ là đường ghi duy nhất của chat và chỉ PHP ghi, sau khi khách xác nhận bằng một câu xác nhận ở lượt kế tiếp; chat-graph và LLM chỉ có tool nháp và tool đọc."
- Hai luật không đổi: không giỏ hàng, không đơn hàng, không thanh toán; thẻ sản phẩm chỉ từ kết quả tool cùng lượt.

## 7. Kiểm thử

- **PHP unit** (SQLite fixture), cho `SupportTicketService`:
  - các trạng thái trả về của `draft`;
  - `create` kiểm tra lại khi trùng hoặc quá giới hạn;
  - đơn của người khác;
  - giới hạn 500 ký tự;
  - `adminUpdate` chỉ nhận 4 trạng thái.
- **PHP unit** cho bước xác nhận trong `ChatbotService`:
  - "đồng ý" tạo ticket và không gọi chat-graph;
  - "thôi" huỷ;
  - câu khác xoá nháp rồi gọi chat-graph;
  - nháp hết hạn;
  - khác `user_id`.
- **Integration** cho `SupportTicketRepository` trên `shop_test`.
- **chat-graph node test**:
  - kết quả `draft` sinh ra `pending_ticket` và câu hỏi xác nhận (model viết thiếu thì code nối vào);
  - `requires_login` hay `duplicate_open` không sinh `pending_ticket`;
  - mã ticket được grounding;
  - allow-list có 11 tool.
- **Eval live**: thêm nhóm `ticket`, khoảng 6 ca theo các kịch bản A, C, D, E, F và lượt hỏi trạng thái. Fixture eval cần tạo được đơn cho user eval qua `OrderService` (thêm tuỳ chọn `orders` vào `fixtures.php`). Trường `pending_ticket` cần một khoá `expect` mới, thêm vào `parseCases` và `scorer`.
- Trang admin và trang tài khoản: thêm vào `scripts/dev/web-snapshot.sh` và `smoke.sh`.

## 8. Để lại cho người làm

- Câu chữ cố định chính xác (xác nhận, đã gửi, đã huỷ), giữ đúng ý §4.
- Cách hiển thị danh sách và form admin, theo kiểu các trang admin hiện có.
- Danh sách cụm từ xác nhận và từ chối, giữ ngắn và có test.
- Thứ tự commit và cách chia task trong kế hoạch.
