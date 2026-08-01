# Bộ test case thủ công - Fashion Shop Chatbot

**Mã tài liệu:** MTC-FS-CB-001  
**Phiên bản:** 1.0  
**Ngày lập:** 29/07/2026  
**Phạm vi:** 11 use case trong SRS, mỗi use case có đúng 5 test case, tổng cộng 55 test case.

## 1. Hướng dẫn sử dụng

Mỗi test case được thực hiện độc lập, trừ trường hợp có ghi rõ là kiểm thử nhiều lượt trong cùng phiên chat.

Khi kiểm tra trên UI:

1. Mở chatbot trên website.
2. Nhập đúng câu test tại cột **Dữ liệu/Bước thực hiện**.
3. Kiểm tra nội dung trả lời và các product card.
4. Mở Developer Tools > Network > request `POST /api/chatbot` để kiểm tra JSON khi test yêu cầu xác minh `primary_intent`, `products`, `knowledge_sources`, `session_id` hoặc `response_type`.
5. Ghi kết quả thực tế và đánh dấu Pass/Fail.

Khi kiểm tra bằng API, dùng mẫu:

```bash
curl -i -X POST http://localhost/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"tìm áo màu đen","session_token":""}'
```

Với test đăng nhập:

```bash
curl -i -X POST http://localhost/api/chatbot \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <API_TOKEN>' \
  -d '{"message":"đơn của tôi đang ở đâu?","session_token":""}'
```

Không ghi API key, password hoặc token thật vào tài liệu hay kết quả test được commit lên Git.

## 2. Chuẩn bị môi trường

```bash
docker compose up -d
docker compose ps
docker compose exec -T app php scripts/ingest_knowledge.php
```

Chuẩn bị các đối tượng kiểm thử:

- **Guest:** cửa sổ ẩn danh, không có Bearer token.
- **User A:** tài khoản test đã đăng nhập và có ít nhất một đơn hàng. Với seed DB hiện tại, user ID 3 sở hữu đơn 1-4.
- **User B:** tài khoản test khác User A, không sở hữu đơn 1-4; nên để không có đơn hàng để test trường hợp rỗng.
- **DeepSeek enabled:** cần cho test semantic completion, ghi rõ tại từng test.
- **Baseline dữ liệu:** dùng `sql/shop_db.sql` chưa chỉnh sửa, trừ test có tiền điều kiện riêng.

Một số sản phẩm seed được dùng để đối chiếu:

| ID | Sản phẩm | Giá | Stock | Thuộc tính liên quan |
|---|---|---:|---:|---|
| 50 | Áo Thun Cotton Basic Trắng | 180.000đ | 0 | trắng, cotton, S/M/L/XL |
| 51 | Áo Sơ Mi Linen Tay Ngắn Xanh | 320.000đ | 5 | linen, trẻ trung, S/M/L/XL |
| 52 | Áo Khoác Bomber Kaki Đen | 550.000đ | 12 | đen, kaki, S/M/L/XL |
| 53 | Áo Len Cổ Tròn Xám | 415.000đ | 8 | xám, len, S/M/L/XL |
| 58 | Áo Thun Graphic Phối Màu | 210.000đ | 15 | S/M/L/XL |
| 63 | Áo Sơ Mi Caro Đỏ Đen | 350.000đ | 1 | đỏ, đen, caro, S/M/L/XL |

## 3. Quy ước đánh giá

- **Pass:** tất cả kết quả mong đợi của test case đều đúng.
- **Fail:** sai ít nhất một điều kiện, bao gồm trả card sai, lộ dữ liệu, gọi sai intent hoặc trả lời không có evidence.
- Không bắt buộc câu trả lời giống từng chữ. Đánh giá theo ý nghĩa, dữ liệu và response fields.
- Product card hợp lệ phải có `id`, `name`, `price`, `stock`, `url`; URL phải là đường dẫn tương đối và không chứa `localhost`.

---

## UC-01 - Mở, tạo hoặc tiếp tục phiên chat

**Phạm vi bao phủ:** guest mới, guest tiếp tục phiên, token không hợp lệ, user đăng nhập, input không hợp lệ.

### TC-UC01-01 - Guest tạo phiên chat mới

**Tiền điều kiện:** Mở cửa sổ ẩn danh; không gửi `session_token`; không có Bearer token.

**Dữ liệu/Bước thực hiện:**

1. Gửi: `tìm áo thun`.
2. Kiểm tra response API.

**Kết quả mong đợi:**

- HTTP 200.
- Có `session_token` mới, không rỗng.
- Có `session_id` là số dương.
- Có `message`; request được xử lý bình thường.
- DB có một `chat_sessions` mới và tối thiểu hai `chat_messages` tương ứng user/bot.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC01-02 - Guest tiếp tục đúng phiên bằng session token

**Tiền điều kiện:** Đã hoàn thành TC-UC01-01 và giữ lại `session_token`, `session_id`.

**Dữ liệu/Bước thực hiện:**

1. Gửi lượt hai với token cũ: `tìm áo len`.
2. So sánh response với lượt đầu.

**Kết quả mong đợi:**

- HTTP 200.
- `session_id` lượt hai bằng `session_id` lượt đầu.
- `session_token` không bị đổi.
- Message mới được nối vào cùng session, không tạo session khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC01-03 - Guest gửi session token không tồn tại

**Tiền điều kiện:** Không đăng nhập.

**Dữ liệu/Bước thực hiện:**

1. Gửi `session_token` là chuỗi 64 ký tự không tồn tại trong DB.
2. Message: `tìm quần jeans`.

**Kết quả mong đợi:**

- HTTP 200.
- API không sử dụng token giả.
- API sinh `session_token` và `session_id` mới.
- Query vẫn được xử lý, không lộ lỗi SQL hoặc stack trace.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC01-04 - User đăng nhập dùng lại session active gần nhất

**Tiền điều kiện:** User A có Bearer token hợp lệ và đã có một `chat_sessions` trạng thái `active`.

**Dữ liệu/Bước thực hiện:**

1. Gửi `tìm váy maxi` với Bearer token User A.
2. Ghi nhận `session_id`.
3. Gửi tiếp `tìm quần tây` với cùng Bearer token nhưng truyền một `session_token` khác.

**Kết quả mong đợi:**

- Cả hai request HTTP 200.
- API ưu tiên session active của User A.
- `session_id` hai response giống nhau.
- Session được gắn đúng `user_id` của User A.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC01-05 - Từ chối message rỗng

**Tiền điều kiện:** Không yêu cầu đăng nhập.

**Dữ liệu/Bước thực hiện:**

```json
{"message":"   ","session_token":""}
```

**Kết quả mong đợi:**

- HTTP 400.
- Response báo `Message is required`.
- Không gọi orchestrator/tool.
- Không tạo chat message rỗng.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-02 - Tìm sản phẩm theo nhiều điều kiện

**Phạm vi bao phủ:** loại/category, alias màu, khoảng giá, size, stock, style/avoid, kết quả rỗng và cache isolation.

### TC-UC02-01 - Chuẩn hóa màu tiếng Anh sang canonical tiếng Việt

**Tiền điều kiện:** Baseline sản phẩm seed.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo màu black`.

**Kết quả mong đợi:**

- `primary_intent = product_search`.
- Có product cards.
- Mọi card phải có `đen` trong `available_colors` hoặc tên/mô tả match màu đen.
- Với seed hiện tại, chỉ được trả các áo đen phù hợp như ID 52 và 63; không được trả áo trắng, xám, xanh.
- Message không được nói tìm thấy toàn bộ 15 sản phẩm áo.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC02-02 - Kết hợp loại sản phẩm, khoảng giá và màu

**Tiền điều kiện:** Baseline sản phẩm seed.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo từ 300k đến 500k màu xám`.

**Kết quả mong đợi:**

- `primary_intent = product_search`.
- Mọi sản phẩm có `category_id = 1`.
- Mọi giá nằm trong khoảng 300.000đ đến 500.000đ.
- Mọi sản phẩm match màu xám.
- Với seed hiện tại, kết quả mong đợi là ID 53, giá 415.000đ.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC02-03 - Kết hợp size, màu, stock và giá tối đa

**Tiền điều kiện:** Baseline `product_sizes`.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo size M màu đen còn hàng dưới 600k`.

**Kết quả mong đợi:**

- `primary_intent = product_search`, `response_type = final_answer`.
- Mọi card có `M` trong `available_sizes`.
- Mọi card có `đen` trong `available_colors`.
- Mọi card có `stock > 0` và `price <= 600000`.
- Với seed hiện tại, có thể trả ID 52 và 63; không được trả ID 50 hoặc sản phẩm hết hàng.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC02-04 - Semantic style và thuộc tính cần tránh

**Tiền điều kiện:** `LLM_PROVIDER`, `LLM_API_KEY` hoạt động; baseline sản phẩm seed.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo sơ mi phong cách trẻ trung, tránh caro`.

**Kết quả mong đợi:**

- `primary_intent = product_search`.
- Routing có thể là `partial_llm_completion`.
- Kết quả phải là áo sơ mi, match phong cách trẻ trung và không chứa caro.
- Với seed hiện tại, ID 51 phù hợp.
- ID 63 phải bị loại vì có thuộc tính `caro`.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC02-05 - Cache không trộn màu và xử lý màu không tồn tại

**Tiền điều kiện:** Giữ nguyên một phiên chat; Redis/file cache đang bật.

**Dữ liệu/Bước thực hiện:**

1. Gửi `tìm áo màu đen`; ghi danh sách ID.
2. Gửi `tìm áo màu trắng`; ghi danh sách ID.
3. Gửi `tìm áo màu tím`.

**Kết quả mong đợi:**

- Lượt 1 chỉ có áo đen.
- Lượt 2 không tái sử dụng danh sách áo đen; với seed hiện tại có ID 50.
- Lượt 3 không có product card vì dữ liệu áo không có màu tím.
- Message lượt 3 báo chưa tìm thấy sản phẩm phù hợp.
- Cache key phải phân biệt ít nhất `color` và toàn bộ filter liên quan.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-03 - Xem chi tiết sản phẩm

**Phạm vi bao phủ:** ID hợp lệ, ID không tồn tại, size hợp lệ, constraint không hợp lệ, tham chiếu sản phẩm từ memory.

### TC-UC03-01 - Xem chi tiết sản phẩm tồn tại

**Tiền điều kiện:** Product ID 52 tồn tại.

**Dữ liệu/Bước thực hiện:** Gửi `cho tôi xem chi tiết sản phẩm mã 52`.

**Kết quả mong đợi:**

- `primary_intent = product_detail`.
- Có đúng một card và `products[0].id = 52`.
- Tên là `Áo Khoác Bomber Kaki Đen`.
- Giá 550.000đ, stock 12 theo seed hiện tại.
- Có `available_sizes` chứa S, M, L, XL và `available_colors` chứa `đen`.
- URL card bắt đầu bằng `/product.php?id=52`, không chứa localhost.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC03-02 - Product ID không tồn tại

**Tiền điều kiện:** Không có product ID 9999.

**Dữ liệu/Bước thực hiện:** Gửi `xem sản phẩm mã 9999`.

**Kết quả mong đợi:**

- `primary_intent = product_detail`.
- Không có product card.
- Message nói chưa tìm thấy sản phẩm mã 9999.
- Không trả card của sản phẩm gần nhất hoặc sản phẩm tương tự.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC03-03 - Kiểm tra size cụ thể của sản phẩm

**Tiền điều kiện:** Product ID 52 có size L.

**Dữ liệu/Bước thực hiện:** Gửi `sản phẩm mã 52 còn size L không?`.

**Kết quả mong đợi:**

- Route thành `product_detail`, không route thành `size_advice`.
- Card ID 52 được giữ lại vì có size L.
- Response có thông tin size hiện có và không yêu cầu chiều cao/cân nặng.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC03-04 - Constraint màu không đúng với sản phẩm

**Tiền điều kiện:** Product ID 52 là màu đen, không phải trắng.

**Dữ liệu/Bước thực hiện:** Gửi `sản phẩm mã 52 có màu trắng không?`.

**Kết quả mong đợi:**

- `primary_intent = product_detail`.
- ProductConstraintVerifier không được giữ card 52 như một kết quả thỏa màu trắng.
- Response không khẳng định sản phẩm có màu trắng.
- Không được thay bằng một sản phẩm trắng khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC03-05 - Tham chiếu “sản phẩm này” trong cùng phiên

**Tiền điều kiện:** Dùng cùng một `session_token`.

**Dữ liệu/Bước thực hiện:**

1. Gửi `xem chi tiết sản phẩm mã 52`.
2. Sau khi có card, gửi `sản phẩm này còn hàng không?`.

**Kết quả mong đợi:**

- Lượt 2 dùng `last_product_id = 52` từ memory.
- Lượt 2 route `product_detail`.
- Card vẫn là ID 52 và trả stock hiện tại.
- Không hỏi lại mã sản phẩm và không đổi sang sản phẩm khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-04 - Nhận tư vấn size

**Phạm vi bao phủ:** tư vấn áo, tư vấn váy, thiếu chiều cao, thiếu cân nặng, ngoài toàn bộ bảng size.

### TC-UC04-01 - Tư vấn size áo với đủ số đo

**Tiền điều kiện:** Bảng `size_guides` seed tồn tại.

**Dữ liệu/Bước thực hiện:** Gửi `tôi cao 1m70 nặng 65kg mua áo thì mặc size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- Không có `missing_slots`.
- Tool `suggest_size` được dùng với height 170, weight 65, category_id 1.
- Theo thứ tự bảng hiện tại, kết quả đề xuất là size M.
- Không có product card nếu user không yêu cầu tìm sản phẩm.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC04-02 - Tư vấn size váy

**Tiền điều kiện:** Bảng size category 3 tồn tại.

**Dữ liệu/Bước thực hiện:** Gửi `cao 160cm nặng 50kg mặc váy size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- Tool nhận category_id 3.
- Theo seed hiện tại, size S thỏa khoảng cao 150-160cm và cân nặng 40-50kg.
- Câu trả lời phải nhắc size S.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC04-03 - Thiếu chiều cao

**Tiền điều kiện:** Bắt đầu phiên mới để không lấy chiều cao cũ từ memory.

**Dữ liệu/Bước thực hiện:** Gửi `tôi nặng 65kg thì mặc áo size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- `response_type = clarification`.
- `missing_slots` chứa `height`.
- Câu trả lời hỏi chiều cao; không tự đoán size.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC04-04 - Thiếu cân nặng

**Tiền điều kiện:** Bắt đầu phiên mới để không lấy cân nặng cũ từ memory.

**Dữ liệu/Bước thực hiện:** Gửi `tôi cao 175cm thì mặc quần size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- `response_type = clarification`.
- `missing_slots` chứa `weight`.
- Không gọi tool tư vấn với cân nặng bằng 0 và không tự đoán size.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC04-05 - Số đo nằm ngoài bảng size

**Tiền điều kiện:** Bảng seed chỉ có các khoảng đến khoảng 185cm/85kg tùy category.

**Dữ liệu/Bước thực hiện:** Gửi `tôi cao 190cm nặng 100kg mua áo thì chọn size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- Tool được gọi vì đủ số đo.
- Không có `recommended_size` phù hợp.
- Bot nói chưa có bảng size phù hợp để tư vấn chắc chắn, không bịa XXL.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-05 - Hỏi chính sách shop bằng RAG

**Phạm vi bao phủ:** giao hàng, đổi trả và ngoại lệ, bảo hành, thanh toán/bán sỉ, câu hỏi không có dữ liệu.

### TC-UC05-01 - Chính sách giao hàng và phí ship

**Tiền điều kiện:** Knowledge đã được ingest hoặc lexical knowledge khả dụng.

**Dữ liệu/Bước thực hiện:** Gửi `đơn 300k thì phí ship thế nào, còn đơn từ 500k thì sao?`.

**Kết quả mong đợi:**

- `primary_intent = shipping`.
- `knowledge_sources` không rỗng.
- Câu trả lời nêu: từ 500.000đ được miễn phí; dưới mức này 30.000đ nội thành và 50.000đ ngoại tỉnh.
- Không có product card.
- Nội dung phải đến từ `policy_rag` evidence.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC05-02 - Đổi trả, hàng sale và trách nhiệm phí vận chuyển

**Tiền điều kiện:** Dùng cùng một phiên; knowledge `policies.md` khả dụng.

**Dữ liệu/Bước thực hiện:**

1. Gửi `shop đổi trả trong bao lâu và cần điều kiện gì?`.
2. Gửi `hàng sale 60% có đổi được không?`.
3. Gửi `nếu tôi chọn nhầm size thì ai chịu phí ship, còn shop giao sai size thì sao?`.

**Kết quả mong đợi:**

- Cả ba lượt dùng intent policy/return và có knowledge evidence.
- Lượt 1: 7 ngày, còn tem mác, chưa qua sử dụng.
- Lượt 2: sale trên 50% không áp dụng đổi trả.
- Lượt 3: nhu cầu cá nhân thì khách chịu phí hai chiều; shop giao sai thì shop chịu phí.
- Không được đảo ngược bên chịu phí.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC05-03 - Chính sách bảo hành và ngoại lệ

**Tiền điều kiện:** Knowledge policy khả dụng.

**Dữ liệu/Bước thực hiện:** Gửi `shop bảo hành lỗi đường may bao lâu, phụ kiện bao lâu và lỗi do người dùng có được bảo hành không?`.

**Kết quả mong đợi:**

- `primary_intent = policy`.
- Có `knowledge_sources`.
- Sản phẩm lỗi đường may/vải: 30 ngày.
- Phụ kiện: 15 ngày.
- Lỗi do người dùng: không áp dụng bảo hành.
- Không trả một thời hạn chung sai cho cả hai loại.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC05-04 - Hình thức thanh toán và bán sỉ

**Tiền điều kiện:** FAQ/knowledge khả dụng.

**Dữ liệu/Bước thực hiện:**

1. Gửi `shop hỗ trợ những hình thức thanh toán nào?`.
2. Gửi `shop có bán sỉ không và tối thiểu bao nhiêu sản phẩm?`.

**Kết quả mong đợi:**

- Lượt 1 nêu COD, chuyển khoản, MoMo và VNPay/thẻ.
- Lượt 2 nêu bán sỉ từ 10 sản phẩm và hướng dẫn liên hệ để báo giá.
- Mỗi lượt có knowledge evidence.
- Chatbot không tự tạo giao dịch thanh toán.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC05-05 - Chính sách không có trong kho tri thức

**Tiền điều kiện:** Không thêm tài liệu về gói quà vào knowledge.

**Dữ liệu/Bước thực hiện:** Gửi `theo chính sách shop, shop có miễn phí gói quà và viết thiệp sinh nhật không?`.

**Kết quả mong đợi:**

- Bot không tự cam kết miễn phí gói quà hoặc viết thiệp.
- Nếu retrieval không có evidence phù hợp, bot nói chưa có đủ thông tin trong dữ liệu shop và đề nghị hỏi rõ/liên hệ CSKH.
- Không lấy một chính sách không liên quan để trả lời như sự thật.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-06 - Hỏi kết hợp sản phẩm và chính sách

**Phạm vi bao phủ:** detail + policy, search + policy, giá + shipping, sản phẩm không tồn tại, RAG service bị suy giảm.

### TC-UC06-01 - Product detail kết hợp đổi size

**Tiền điều kiện:** Product 52 và knowledge đổi trả tồn tại.

**Dữ liệu/Bước thực hiện:** Gửi `áo mã 52 còn size L không và đổi size có mất phí ship không?`.

**Kết quả mong đợi:**

- `primary_intent = mixed_product_policy`.
- Có card ID 52 và `available_sizes` chứa L.
- `knowledge_sources` không rỗng.
- Câu trả lời có cả thông tin sản phẩm/size và chính sách đổi size/phí ship.
- Không tự checkout hoặc đổi size thay user.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC06-02 - Tìm bomber còn hàng kết hợp điều kiện đổi trả

**Tiền điều kiện:** Product 52 còn hàng.

**Dữ liệu/Bước thực hiện:** Gửi `áo bomber nếu mua về không vừa size thì đổi được không, và áo đó còn hàng không?`.

**Kết quả mong đợi:**

- `primary_intent = mixed_product_policy`.
- Có card áo bomber ID 52, stock lớn hơn 0.
- Có knowledge source về đổi trả/đổi size.
- Trả đúng cả hai phần; không chỉ trả sản phẩm hoặc chỉ trả policy.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC06-03 - Tìm sản phẩm theo giá kết hợp phí ship

**Tiền điều kiện:** Products 50 và 58 tồn tại.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo thun dưới 300k và cho biết đơn dưới 500k tính phí ship thế nào`.

**Kết quả mong đợi:**

- `primary_intent = mixed_product_policy`.
- Product cards đều là áo thun, giá không quá 300.000đ.
- Có knowledge source về shipping.
- Câu trả lời nêu phí 30.000đ nội thành, 50.000đ ngoại tỉnh cho đơn dưới 500.000đ.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC06-04 - Không có sản phẩm nhưng vẫn có policy hợp lệ

**Tiền điều kiện:** Không có áo khoác màu tím trong seed.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo khoác màu tím và cho biết sản phẩm mua về có đổi trả được không`.

**Kết quả mong đợi:**

- `primary_intent = mixed_product_policy`.
- Không hiển thị product card sai màu.
- Phần sản phẩm nói chưa tìm thấy áo khoác tím.
- Phần policy vẫn dựa trên knowledge đổi trả nếu retrieval thành công.
- Không nói đã tìm thấy sản phẩm chung chung.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC06-05 - Mixed intent khi vector services không khả dụng

**Tiền điều kiện:** Môi trường test cho phép dừng và khởi động lại service.

**Dữ liệu/Bước thực hiện:**

1. Chạy `docker compose stop qdrant rag-ml`.
2. Gửi `tìm áo bomber còn hàng và cho biết đổi size trong bao lâu`.
3. Sau test chạy `docker compose start qdrant rag-ml`.

**Kết quả mong đợi:**

- Product search vẫn trả card ID 52.
- KnowledgeRetriever dùng `lexical_fallback` từ Markdown/FAQ DB.
- Câu trả lời vẫn có chính sách đổi trả nếu lexical evidence đủ.
- API không trả 500 hoặc stack trace.
- Service được khôi phục sau test.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-07 - Tra cứu trạng thái đơn hàng

**Phạm vi bao phủ:** chưa đăng nhập, danh sách đơn, đúng đơn sở hữu, truy cập chéo tài khoản, tài khoản chưa có đơn.

### TC-UC07-01 - Guest hỏi trạng thái đơn

**Tiền điều kiện:** Không có Bearer token.

**Dữ liệu/Bước thực hiện:** Gửi `đơn của tôi đang ở đâu rồi?`.

**Kết quả mong đợi:**

- `primary_intent = order_status`.
- Câu trả lời yêu cầu đăng nhập.
- Không trả trạng thái, giá trị hoặc chi tiết của bất kỳ đơn hàng nào.
- Không có product card.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC07-02 - User xem đơn gần nhất

**Tiền điều kiện:** Đăng nhập User A, user này sở hữu đơn seed 1-4.

**Dữ liệu/Bước thực hiện:** Gửi `đơn của tôi đang ở trạng thái nào?`.

**Kết quả mong đợi:**

- `primary_intent = order_status`.
- Không yêu cầu đăng nhập lại.
- Tool chỉ lấy tối đa 5 đơn của User A, sắp xếp mới nhất trước.
- Với seed hiện tại, câu trả lời có thể nêu đơn #4 trạng thái `Đang giao`.
- Không lộ địa chỉ, số điện thoại hoặc đơn user khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC07-03 - User xem đúng một đơn thuộc tài khoản

**Tiền điều kiện:** Đăng nhập User A; đơn #3 thuộc User A.

**Dữ liệu/Bước thực hiện:** Gửi `kiểm tra trạng thái đơn hàng 3`.

**Kết quả mong đợi:**

- Tool query đồng thời `orders.id = 3` và `orders.user_id = User A`.
- Câu trả lời nêu đơn #3 đang `Đang giao` theo seed hiện tại.
- Không trả danh sách các đơn khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC07-04 - Chặn xem đơn của tài khoản khác

**Tiền điều kiện:** Đăng nhập User B; đơn #1 thuộc User A, không thuộc User B.

**Dữ liệu/Bước thực hiện:** Gửi `kiểm tra trạng thái đơn hàng 1`.

**Kết quả mong đợi:**

- `primary_intent = order_status`.
- Response nói không tìm thấy đơn này trong tài khoản.
- Không tiết lộ trạng thái `Đã hoàn thành`, tổng tiền hoặc thời gian của đơn #1.
- API vẫn trả response an toàn, không trả lỗi SQL.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC07-05 - User đăng nhập nhưng chưa có đơn

**Tiền điều kiện:** Đăng nhập User B và bảo đảm User B không có record trong `orders`.

**Dữ liệu/Bước thực hiện:** Gửi `đơn của tôi`.

**Kết quả mong đợi:**

- `primary_intent = order_status`.
- Không yêu cầu đăng nhập.
- Response báo chưa tìm thấy/chưa có đơn hàng trong tài khoản.
- Không trả đơn seed của User A.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-08 - Tải lịch sử và sử dụng memory

**Phạm vi bao phủ:** history rỗng, history có product cards, guest continuity, tham chiếu sản phẩm, long-term memory.

### TC-UC08-01 - User chưa có lịch sử chat

**Tiền điều kiện:** User B đăng nhập; không có session active hoặc session active chưa có message.

**Dữ liệu/Bước thực hiện:**

1. Mở chatbot.
2. Kiểm tra request `GET /api/chatbot/history`.

**Kết quả mong đợi:**

- HTTP 200.
- `messages` là mảng rỗng.
- Nếu không có session, `session_token` là null.
- UI hiển thị nhóm câu hỏi gợi ý, không hiển thị message của user khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC08-02 - Khôi phục message và product cards

**Tiền điều kiện:** User A đăng nhập.

**Dữ liệu/Bước thực hiện:**

1. Gửi `tìm áo màu đen`.
2. Đóng widget hoặc reload trang.
3. Mở widget lại.

**Kết quả mong đợi:**

- UI gọi history API với Bearer token.
- Message user và bot được tải đúng thứ tự.
- Product cards đã lưu trong metadata được render lại.
- Không nhân đôi message nếu widget đã có nội dung.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC08-03 - Guest tiếp tục hội thoại trong cùng browser session

**Tiền điều kiện:** Cửa sổ ẩn danh mới.

**Dữ liệu/Bước thực hiện:**

1. Gửi `tìm áo thun`.
2. Ghi `session_id` trả về.
3. Gửi `tìm áo len` trong cùng widget.
4. Reload trang và gửi thêm `tìm áo khoác`.

**Kết quả mong đợi:**

- Các lượt trong cùng phiên browser phải dùng cùng session/token.
- Sau reload, session continuity phải được giữ theo token của widget/PHP session.
- Nếu `session_id` đổi sau reload, ghi nhận Fail vì guest history bị đứt.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC08-04 - Memory giải quyết đại từ chỉ sản phẩm

**Tiền điều kiện:** Dùng cùng một session.

**Dữ liệu/Bước thực hiện:**

1. Gửi `xem sản phẩm mã 52`.
2. Gửi `áo này giá bao nhiêu và còn hàng không?`.

**Kết quả mong đợi:**

- Lượt hai dùng `last_product_id = 52`.
- Trả đúng sản phẩm ID 52, giá và stock mới từ DB.
- Không lấy product ID từ user/session khác.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC08-05 - Long-term memory chỉ lưu cho user đăng nhập

**Tiền điều kiện:** Có quyền xem DB test; chuẩn bị một guest session và User B đăng nhập.

**Dữ liệu/Bước thực hiện:**

1. Với User B, gửi `mình mặc L và không thích chất liệu da`.
2. Kiểm tra `user_long_term_memory` của User B.
3. Với guest, gửi cùng câu.
4. Kiểm tra không có `user_long_term_memory` mới cho guest.

**Kết quả mong đợi:**

- User B có `stable_facts.usual_size = L`.
- Preference/feedback của User B ghi nhận cần tránh hoặc không thích `da`.
- Guest chỉ có `chat_session_memory`, không có row long-term vì không có user_id.
- Dữ liệu User B không xuất hiện trong memory của guest.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-09 - Làm rõ query thiếu hoặc xung đột

**Phạm vi bao phủ:** unknown intent, thiếu toàn bộ số đo, thiếu từng slot, conflict chưa giải quyết, conflict có tín hiệu sửa.

### TC-UC09-01 - Query không xác định được nhu cầu

**Tiền điều kiện:** Phiên chat mới.

**Dữ liệu/Bước thực hiện:** Gửi `abcxyz nội dung không liên quan`.

**Kết quả mong đợi:**

- `primary_intent = unknown`.
- `response_type` là `fallback` hoặc clarification theo pipeline.
- Bot hỏi user nói rõ muốn tìm sản phẩm, xem chi tiết, hỏi size, chính sách hay đơn hàng.
- Không gọi product/order tool và không hiển thị card.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC09-02 - Hỏi size nhưng thiếu cả chiều cao và cân nặng

**Tiền điều kiện:** Phiên mới, không có slot số đo.

**Dữ liệu/Bước thực hiện:** Gửi `mình mặc size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- `response_type = clarification`.
- `missing_slots` chứa cả `height` và `weight`.
- Không gọi `suggest_size`.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC09-03 - Thiếu một slot size advice

**Tiền điều kiện:** Phiên mới.

**Dữ liệu/Bước thực hiện:** Gửi `mình cao 170cm thì mặc size gì?`.

**Kết quả mong đợi:**

- `primary_intent = size_advice`.
- `missing_slots` chứa `weight`, không chứa `height`.
- Bot chỉ hỏi thêm cân nặng.
- Không tự giả định cân nặng.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC09-04 - Hai mức ngân sách mâu thuẫn, không có tín hiệu sửa

**Tiền điều kiện:** Phiên mới.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo dưới 500k nhưng cũng dưới 300k`.

**Kết quả mong đợi:**

- ConflictDetector phát hiện hai candidate khác nhau cho `max_price`.
- ConflictResolver không tự chọn vì không có tín hiệu sửa.
- Bot hỏi xác nhận ngân sách 500.000đ hay 300.000đ.
- Không gọi `search_products` trước khi user xác nhận.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC09-05 - Xung đột có tín hiệu người dùng sửa lại

**Tiền điều kiện:** Phiên mới.

**Dữ liệu/Bước thực hiện:** Gửi `tìm áo dưới 500k, à không, chốt dưới 300k`.

**Kết quả mong đợi:**

- ConflictResolver nhận tín hiệu `à không`/`chốt`.
- Chọn candidate cuối là `max_price = 300000`.
- Không hỏi lại ngân sách.
- Nếu có product cards, mọi card có giá không quá 300.000đ.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-10 - Từ chối hành động không được hỗ trợ

**Phạm vi bao phủ:** phối đồ, tạo set, thêm giỏ hàng, checkout/thanh toán hộ, chốt đơn.

### TC-UC10-01 - Yêu cầu phối một sản phẩm với sản phẩm khác

**Tiền điều kiện:** Không yêu cầu đăng nhập.

**Dữ liệu/Bước thực hiện:** Gửi `áo thun trắng phối với quần gì cho đẹp?`.

**Kết quả mong đợi:**

- `primary_intent = unsupported_outfit`.
- Bot nói hiện không hỗ trợ tư vấn phối đồ.
- Bot có thể nêu phạm vi hỗ trợ: tìm sản phẩm, chi tiết, size và chính sách.
- Không gọi outfit API/tool và không đưa ra set phối đồ.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC10-02 - Yêu cầu tạo trọn bộ outfit

**Tiền điều kiện:** Không yêu cầu đăng nhập.

**Dữ liệu/Bước thực hiện:** Gửi `phối giúp tôi một set đồ đi chơi gồm áo và quần`.

**Kết quả mong đợi:**

- Route `unsupported_outfit`.
- Không dùng dữ liệu `outfit_suggestions`.
- Không trả danh sách set hoặc khẳng định đã phối đồ.
- Không tạo cart.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC10-03 - Yêu cầu thêm vào giỏ hàng

**Tiền điều kiện:** Ghi lại số dòng/quantity hiện có trong cart của user test nếu đang đăng nhập.

**Dữ liệu/Bước thực hiện:** Gửi `thêm áo mã 52 size M vào giỏ giúp tôi`.

**Kết quả mong đợi:**

- `primary_intent = unsupported_checkout`.
- Bot nói không thể tự thêm giỏ hàng.
- Không có request `POST /api/cart`.
- Bảng cart không thay đổi.
- Có thể hướng dẫn user bấm product card hoặc trang chi tiết.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC10-04 - Yêu cầu checkout và thanh toán hộ

**Tiền điều kiện:** Không có giao dịch thanh toán đang chạy.

**Dữ liệu/Bước thực hiện:** Gửi `checkout và thanh toán giúp tôi áo thun trắng size M`.

**Kết quả mong đợi:**

- Route `unsupported_checkout`.
- Không chuyển trang, không redirect đến payment.
- Không tạo order hoặc payment.
- Không trả nội dung “đã thanh toán”, “đã chuẩn bị giỏ hàng”.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC10-05 - Yêu cầu chốt đơn trực tiếp

**Tiền điều kiện:** Ghi số lượng order trước test.

**Dữ liệu/Bước thực hiện:** Gửi `chốt đơn áo mã 52 cho tôi`.

**Kết quả mong đợi:**

- Route `unsupported_checkout`.
- Không tạo record mới trong `orders` hoặc `order_items`.
- Bot hướng dẫn user tự mở card/trang chi tiết để mua.
- Không yêu cầu hoặc hiển thị thông tin thanh toán nhạy cảm trong chat.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## UC-11 - Index và cập nhật kho tri thức

**Phạm vi bao phủ:** index thành công, chạy lặp, Qdrant lỗi, embedding service lỗi, cập nhật nội dung và cache version.

### TC-UC11-01 - Index knowledge thành công

**Tiền điều kiện:** `db`, `qdrant`, `rag-ml`, `app` đang healthy; env RAG hợp lệ.

**Dữ liệu/Bước thực hiện:**

```bash
docker compose exec -T app php scripts/ingest_knowledge.php
```

**Kết quả mong đợi:**

- Command exit code 0.
- JSON output có `documents > 0`.
- `result.success = true`.
- `result.count` bằng số documents được xử lý.
- Qdrant có collection cấu hình cho knowledge và vector size 768.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC11-02 - Chạy index lặp không tạo dữ liệu logic trùng

**Tiền điều kiện:** TC-UC11-01 đã Pass.

**Dữ liệu/Bước thực hiện:**

1. Ghi `documents` và `result.count` lần đầu.
2. Chạy lại cùng command ingest mà không thay đổi knowledge.
3. Kiểm tra collection và thử một policy query.

**Kết quả mong đợi:**

- Lần hai vẫn `success = true`.
- Document count không tăng bất thường.
- Stable point ID khiến tài liệu cũ được upsert thay vì nhân bản logic.
- Policy query không trả nhiều đoạn trùng lặp giống hệt nhau.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC11-03 - Qdrant không khả dụng

**Tiền điều kiện:** Môi trường test cho phép dừng service.

**Dữ liệu/Bước thực hiện:**

1. Chạy `docker compose stop qdrant`.
2. Chạy ingest.
3. Gửi một policy query qua chatbot.
4. Chạy `docker compose start qdrant`.

**Kết quả mong đợi:**

- Ingest trả `result.success = false` hoặc thông báo không thể tạo/xác minh collection; không crash PHP.
- Runtime policy query vẫn có thể dùng lexical fallback từ Markdown/FAQ DB.
- Chatbot không trả stack trace.
- Qdrant được khôi phục sau test.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC11-04 - rag-ml/embedding service không khả dụng

**Tiền điều kiện:** Qdrant đang chạy; `EMBEDDING_PROVIDER` không phải `local_hash`.

**Dữ liệu/Bước thực hiện:**

1. Chạy `docker compose stop rag-ml`.
2. Chạy ingest.
3. Gửi `shop đổi trả trong bao lâu?`.
4. Chạy `docker compose start rag-ml`.

**Kết quả mong đợi:**

- Ingest báo embedding failed và `success = false`, không ghi vector giả.
- Runtime query vẫn dùng lexical fallback nếu local documents/FAQ khả dụng.
- API không trả HTTP 500 chỉ vì rag-ml dừng.
- rag-ml được khôi phục sau test.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

### TC-UC11-05 - Cập nhật knowledge và tránh retrieval cache cũ

**Tiền điều kiện:** Chỉ thực hiện trên DB/môi trường test; các RAG service đang chạy.

**Dữ liệu/Bước thực hiện:**

1. Thêm một FAQ test có câu hỏi duy nhất, ví dụ `Mã chính sách kiểm thử ALPHA-2026 là gì?`, answer `ALPHA-2026 có hiệu lực trong môi trường test`.
2. Tăng `KNOWLEDGE_VERSION` cho lần test hoặc xóa đúng retrieval cache liên quan.
3. Chạy ingest.
4. Hỏi chatbot đúng câu hỏi ALPHA-2026.
5. Sau test xóa FAQ test và tăng version/re-index lại.

**Kết quả mong đợi:**

- Ingest thành công và document count phản ánh FAQ mới.
- Chatbot lấy được answer ALPHA-2026 từ knowledge source, không bịa thêm.
- Kết quả không bị retrieval cache phiên bản cũ che mất.
- Dữ liệu test được dọn khỏi DB/knowledge sau khi hoàn tất.

**Kết quả thực tế:** ........................................................  
**Trạng thái:** Pass / Fail

---

## 4. Ma trận tổng hợp phạm vi

| Use case | Số test | Happy path | Negative/validation | Auth/privacy | Memory/cache | Service fallback/operation |
|---|---:|:---:|:---:|:---:|:---:|:---:|
| UC-01 Session chat | 5 | Có | Có | Có | Có | Không áp dụng |
| UC-02 Product search | 5 | Có | Có | Không áp dụng | Có | Semantic LLM |
| UC-03 Product detail | 5 | Có | Có | Không áp dụng | Có | Cache detail |
| UC-04 Size advice | 5 | Có | Có | Không áp dụng | Tách phiên | Không áp dụng |
| UC-05 Policy RAG | 5 | Có | Có | Không áp dụng | Retrieval | Lexical/vector |
| UC-06 Mixed intent | 5 | Có | Có | Không áp dụng | Có | RAG degraded |
| UC-07 Order status | 5 | Có | Có | Có | Không áp dụng | Không áp dụng |
| UC-08 History/memory | 5 | Có | Có | Có | Có | Không áp dụng |
| UC-09 Clarification | 5 | Có | Có | Không áp dụng | Tách phiên | Không áp dụng |
| UC-10 Unsupported actions | 5 | Có | Có | Có | Không áp dụng | Không thay đổi dữ liệu |
| UC-11 Knowledge ingest | 5 | Có | Có | Không áp dụng | Cache version | Có |
| **Tổng** | **55** |  |  |  |  |  |

## 5. Báo cáo kết quả

Sau khi chạy xong, tổng hợp:

| Chỉ số | Giá trị |
|---|---:|
| Tổng test case | 55 |
| Pass |  |
| Fail |  |
| Blocked |  |
| Chưa chạy |  |
| Tỷ lệ Pass |  |

Với mỗi test Fail, nên ghi:

- Test case ID.
- Query và thứ tự các lượt chat.
- Response JSON đã lược bỏ token/secrets.
- Screenshot UI.
- `trace_id`.
- Kết quả mong đợi và kết quả thực tế.
- Log liên quan của `app`, `rag-ml`, `qdrant` hoặc `reranker`.
