# HƯỚNG DẪN THỰC HÀNH THỦ CÔNG TRÊN POSTMAN

## 0. Dành cho Postman Desktop trên Ubuntu

Ảnh của bạn cho thấy đang dùng **Postman Desktop**, không phải Postman Web.

- Không cần cài hoặc chọn Desktop Agent.
- Chữ **Online** ở góc dưới bên trái nghĩa là Postman Desktop đang hoạt động.
- Dòng **No environment** ở phía trên không phải lỗi. Collection này dùng **Collection Variables**, không cần Environment.
- Collection `Shop Quan Ao - Kiem thu Admin 2026-06-...` đã có sẵn trong sidebar, vì vậy không cần tạo lại collection và các request.

### Việc cần làm ngay trên màn hình trong ảnh

1. Ở bảng bên phải có dòng **Visualize response as chart**:
   - Bấm **Reject**.
   - Bấm dấu **X** ở góc trên bên phải của bảng này để đóng bảng AI.
   - Không cần bấm **Approve**; bảng đó không liên quan đến testcase.
2. Bạn đang mở đúng request:
   `TC-AUTH-01 - Dang nhap admin`.
3. Method `POST` và URL `{{base_url}}/admin/login.php` đang đúng.
4. Bạn đang đứng ở tab **Params** nên phía dưới chỉ thấy `Query Params`.
5. Sau khi đóng bảng bên phải, hàng tab sẽ hiện đủ:
   `Params | Authorization | Headers | Body | Scripts | Settings`.
6. Bấm tab **Body** để nhập tài khoản đăng nhập.
7. Khi xem code kiểm thử, bấm:
   **Scripts > Post-response**.
8. Khi xem kết quả sau khi Send, ở khung **Response** phía dưới chọn:
   **Test Results**.

### Kiểm tra biến collection đang có

1. Trong sidebar, bấm trực tiếp vào tên collection
   `Shop Quan Ao - Kiem thu Admin 2026-06-...`, không bấm vào request con.
2. Chọn tab **Variables**.
3. Kiểm tra các biến:

| Variable | Giá trị cần dùng |
|---|---|
| `base_url` | `http://localhost:8080` |
| `product_id` | ID sản phẩm test mới |
| `product_name` | `QA_DELETE_PRODUCT_MANUAL_20260609` |
| `member_id` | `4` |
| `member_username` | `qa_member_20260605` |

4. Nếu giao diện có hai cột **Initial value** và **Current value**, điền cùng một giá trị vào cả hai cột.
5. Bấm **Save**.
6. Quay lại request, đưa chuột lên `{{base_url}}`. Postman phải hiện giá trị
   `http://localhost:8080`.

## 1. Chuẩn bị

1. Kiểm tra website mở được tại `http://localhost:8080`.
2. Giữ Docker đang chạy. Có thể kiểm tra bằng cách mở:
   - Website: `http://localhost:8080`
   - phpMyAdmin: `http://localhost:8081`
3. Bạn đã có collection được import sẵn. Chỉ tạo collection mới nếu collection
   `Shop Quan Ao - Kiem thu Admin 2026-06-...` không còn.
4. Nếu phải tạo collection mới, đặt tên:
   `Shop Quan Ao - Kiem thu Admin thu cong`.
5. Trong collection, mở tab **Variables** và tạo:

| Variable | Initial/Current value |
|---|---|
| `base_url` | `http://localhost:8080` |
| `product_id` | Điền sau khi tạo sản phẩm test |
| `product_name` | `QA_DELETE_PRODUCT_MANUAL_20260609` |
| `member_id` | `4` |
| `member_username` | `qa_member_20260605` |

6. Nếu collection chưa có request, tạo hai folder:
   - `01 - Xoa san pham`
   - `02 - Quan ly thanh vien`

Nếu collection và các request đã có giống ảnh của bạn, bỏ qua bước tạo collection,
folder, request và script. Bạn chỉ cần kiểm tra dữ liệu, bấm đúng request rồi thực
hiện theo thứ tự.

## 2. Tạo lại dữ liệu sản phẩm test

Sản phẩm ID 105 trong báo cáo đã được xóa nên không dùng lại được.

1. Mở `http://localhost:8080/admin/login.php`.
2. Đăng nhập:
   - Username: `admin`
   - Password: `123456`
3. Chọn **Quản lý sản phẩm**.
4. Thêm sản phẩm:
   - Tên: `QA_DELETE_PRODUCT_MANUAL_20260609`
   - Giá: `123456`
   - Ảnh: chọn một file `.jpg` hoặc `.png` bất kỳ.
   - Mô tả: `Du lieu kiem thu xoa san pham bang Postman`.
5. Bấm **LƯU SẢN PHẨM**.
6. Ghi lại ID của sản phẩm vừa tạo ở dòng đầu danh sách.
7. Quay lại collection Postman > **Variables**, điền ID đó vào `product_id`, rồi **Save**.

Không dùng sản phẩm thật vì testcase TC-SP-03 sẽ xóa sản phẩm.

## 3. Quy tắc session và cookie

- Các request phải dùng cùng collection và cùng Postman để cookie `PHPSESSID` được giữ lại.
- Nhấn **Cookies** cạnh ô URL để xem cookie của `localhost`.
- Trước TC-SP-01, xóa cookie `PHPSESSID` hoặc chọn **Clear All Cookies**.
- Từ TC-AUTH-01 trở đi, không xóa cookie.
- Trong tab **Settings** của mỗi request, bảo đảm **Disable cookie jar = OFF**.
- TC-SP-01 phải tắt **Automatically follow redirects** để nhìn thấy HTTP 302.
- Các request còn lại bật **Automatically follow redirects**.

## 4. TC-SP-01 - Chưa đăng nhập không được truy cập

1. Trong folder `01 - Xoa san pham`, chọn **Add request**.
2. Tên: `TC-SP-01 - Chua dang nhap khong duoc truy cap`.
3. Method: `GET`.
4. URL: `{{base_url}}/admin/manage_products.php`.
5. Tab **Settings**:
   - Automatically follow redirects: **OFF**.
   - Disable cookie jar: **OFF**.
6. Mở **Scripts > Post-response**, dán:

```javascript
pm.test('Tra ve HTTP 302', function () {
  pm.response.to.have.status(302);
});

pm.test('Chuyen huong den trang dang nhap', function () {
  pm.expect(pm.response.headers.get('Location')).to.include('../login.php');
});
```

7. Bấm **Save**, xóa cookie `PHPSESSID`, rồi bấm **Send**.
8. Kết quả đúng:
   - Status: `302 Found`.
   - Header `Location`: `../login.php`.
   - Tab **Test Results**: `2 PASS`.
9. Minh chứng giao diện: mở `http://localhost:8080/admin/manage_products.php` trong cửa sổ chưa đăng nhập; hệ thống chuyển về trang đăng nhập.

## 5. TC-AUTH-01 - Đăng nhập admin

1. Trong sidebar, bấm request `TC-AUTH-01 - Dang nhap admin`.
2. Kiểm tra Method là `POST`.
3. Kiểm tra URL là `{{base_url}}/admin/login.php`.
4. Bấm **Body**. Không nhập tài khoản trong `Params`.
5. Trong Body, chọn loại **x-www-form-urlencoded**.
6. Nhập ba dòng:

| KEY | VALUE |
|---|---|
| `username` | `admin` |
| `password` | `123456` |
| `login` | `1` |

Postman sẽ tự thêm `Content-Type: application/x-www-form-urlencoded`.

7. Tab **Settings**:
   - Automatically follow redirects: **ON**.
   - Disable cookie jar: **OFF**.
8. **Scripts > Post-response**:

```javascript
pm.test('Dang nhap thanh cong', function () {
  pm.response.to.have.status(200);
  pm.expect(pm.response.text()).to.include('Tổng quan hệ thống');
});
```

9. Bấm **Save**, rồi bấm nút xanh **Send**.
10. Kết quả đúng:
   - Status cuối: `200 OK`.
   - Response có chữ `Tổng quan hệ thống`.
   - Test Results: `1 PASS`.
   - Cookies của `localhost` có `PHPSESSID`.
11. Không xóa cookie sau bước này.

### Nếu không thấy tab Body

1. Đóng bảng AI bên phải bằng dấu **X**.
2. Kéo rộng cửa sổ Postman.
3. Tìm dấu `>>` hoặc menu tràn ở cuối hàng tab và chọn **Body**.
4. Không điền `username`, `password`, `login` vào bảng `Query Params`.

## 6. TC-SP-02 - Sản phẩm tồn tại trước khi xóa

1. Add request, tên `TC-SP-02 - San pham ton tai truoc khi xoa`.
2. Method: `GET`.
3. URL: `{{base_url}}/admin/manage_products.php`.
4. **Scripts > Post-response**:

```javascript
pm.test('Danh sach san pham tai thanh cong', function () {
  pm.response.to.have.status(200);
});

pm.test('Tim thay san pham kiem thu', function () {
  pm.expect(pm.response.text()).to.include(pm.variables.get('product_name'));
});
```

5. Bấm **Save > Send**.
6. Kết quả đúng:
   - `200 OK`.
   - Response HTML có `QA_DELETE_PRODUCT_MANUAL_20260609`.
   - Test Results: `2 PASS`.
7. Minh chứng giao diện: mở trang quản lý sản phẩm và chụp dòng chứa sản phẩm test.

## 7. TC-SP-03 - Xóa sản phẩm hợp lệ

1. Add request, tên `TC-SP-03 - Xoa san pham hop le`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_products.php?delete_id={{product_id}}`
4. Automatically follow redirects: **ON**.
5. **Scripts > Post-response**:

```javascript
pm.test('Xoa va tai lai danh sach thanh cong', function () {
  pm.response.to.have.status(200);
});

pm.test('San pham khong con trong danh sach', function () {
  pm.expect(pm.response.text()).to.not.include(pm.variables.get('product_name'));
});
```

6. Bấm **Save > Send** đúng một lần.
7. Kết quả đúng:
   - `200 OK` sau redirect.
   - Test Results: `2 PASS`.
   - Response không còn tên sản phẩm test.
8. Minh chứng giao diện: refresh trang quản lý sản phẩm; sản phẩm test không còn.

## 8. TC-SP-04 - Xóa ID không tồn tại

1. Add request, tên `TC-SP-04 - Xoa ID khong ton tai`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_products.php?delete_id=999999`
4. Automatically follow redirects: **ON**.
5. **Scripts > Post-response**:

```javascript
pm.test('He thong khong phat sinh loi server', function () {
  pm.response.to.have.status(200);
  pm.expect(pm.response.text()).to.include('Quản lý sản phẩm');
});

pm.test('San pham kiem thu van khong xuat hien lai', function () {
  pm.expect(pm.response.text()).to.not.include(pm.variables.get('product_name'));
});
```

6. Bấm **Save > Send**.
7. Kết quả đúng: `200 OK`, `2 PASS`, trang sản phẩm vẫn hiển thị bình thường.

## 9. TC-TV-01 - Thành viên tồn tại và đang hoạt động

1. Trong folder `02 - Quan ly thanh vien`, add request:
   `TC-TV-01 - Thanh vien ton tai va dang hoat dong`.
2. Method: `GET`.
3. URL: `{{base_url}}/admin/manage_users.php`.
4. **Scripts > Post-response**:

```javascript
const body = pm.response.text();
const row = (body.match(new RegExp(
  '<tr[^>]*>[\\s\\S]*?' +
  pm.variables.get('member_username') +
  '[\\s\\S]*?<\\/tr>'
)) || [''])[0];

pm.test('Tim thay thanh vien kiem thu', function () {
  pm.response.to.have.status(200);
  pm.expect(row).to.include(pm.variables.get('member_username'));
});

pm.test('Trang thai ban dau la Hoat dong', function () {
  pm.expect(row).to.include('Hoạt động');
});

pm.test('Quyen ban dau la USER', function () {
  pm.expect(row).to.include('USER');
});
```

5. Bấm **Save > Send**.
6. Kết quả dự kiến: `200 OK`, `3 PASS`.
7. Response/giao diện vẫn có thể hiện warning `Undefined array key "user_id"`; đây là lỗi đã ghi trong báo cáo.

## 10. TC-TV-02 - Khóa thành viên

1. Add request: `TC-TV-02 - Khoa thanh vien`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_users.php?action=lock&id={{member_id}}`
4. **Scripts > Post-response**:

```javascript
const body = pm.response.text();
const row = (body.match(new RegExp(
  '<tr[^>]*>[\\s\\S]*?' +
  pm.variables.get('member_username') +
  '[\\s\\S]*?<\\/tr>'
)) || [''])[0];

pm.test('Thong bao khoa thanh cong', function () {
  pm.response.to.have.status(200);
  pm.expect(body).to.include('Đã khóa tài khoản thành công.');
});

pm.test('Trang thai thanh vien la Da khoa', function () {
  pm.expect(row).to.include('Đã khóa');
});
```

5. Bấm **Save > Send**.
6. Kết quả thực tế của phiên bản hiện tại:
   - HTTP hiển thị `200`, nhưng response chỉ có PHP warning.
   - Test Results: `2 FAIL`.
   - Lỗi gồm `Undefined array key "user_id"` và `Cannot modify header information`.
7. Để kiểm tra dữ liệu thật, mở/refresh `http://localhost:8080/admin/manage_users.php`.
8. Thành viên ID 4 sẽ hiện `Đã khóa`, dù response Postman không đạt.

## 11. TC-TV-03 - Mở khóa thành viên

1. Add request: `TC-TV-03 - Mo khoa thanh vien`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_users.php?action=unlock&id={{member_id}}`
4. **Scripts > Post-response**:

```javascript
const body = pm.response.text();
const row = (body.match(new RegExp(
  '<tr[^>]*>[\\s\\S]*?' +
  pm.variables.get('member_username') +
  '[\\s\\S]*?<\\/tr>'
)) || [''])[0];

pm.test('Thong bao mo khoa thanh cong', function () {
  pm.response.to.have.status(200);
  pm.expect(body).to.include('Đã mở khóa tài khoản thành công.');
});

pm.test('Trang thai thanh vien la Hoat dong', function () {
  pm.expect(row).to.include('Hoạt động');
});
```

5. Bấm **Save > Send**.
6. Kết quả thực tế: `2 FAIL` do cùng lỗi PHP/redirect như TC-TV-02.
7. Refresh giao diện quản lý người dùng; thành viên ID 4 sẽ trở lại `Hoạt động`.

## 12. TC-TV-04 - Đổi quyền USER sang ADMIN

1. Add request: `TC-TV-04 - Doi quyen USER sang ADMIN`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_users.php?toggle_role=admin&id={{member_id}}`
4. **Scripts > Post-response**:

```javascript
const body = pm.response.text();
const row = (body.match(new RegExp(
  '<tr[^>]*>[\\s\\S]*?' +
  pm.variables.get('member_username') +
  '[\\s\\S]*?<\\/tr>'
)) || [''])[0];

pm.test('Thong bao doi quyen thanh cong', function () {
  pm.response.to.have.status(200);
  pm.expect(body).to.include('Đã cập nhật quyền thành công.');
});

pm.test('Quyen thanh vien la ADMIN', function () {
  pm.expect(row).to.include('ADMIN');
});
```

5. Bấm **Save > Send**.
6. Kết quả dự kiến: `200 OK`, `2 PASS`; giao diện hiển thị quyền `ADMIN`.

## 13. TC-TV-05 - Khôi phục ADMIN về USER

1. Add request: `TC-TV-05 - Khoi phuc quyen ADMIN ve USER`.
2. Method: `GET`.
3. URL:
   `{{base_url}}/admin/manage_users.php?toggle_role=user&id={{member_id}}`
4. **Scripts > Post-response**:

```javascript
const body = pm.response.text();
const row = (body.match(new RegExp(
  '<tr[^>]*>[\\s\\S]*?' +
  pm.variables.get('member_username') +
  '[\\s\\S]*?<\\/tr>'
)) || [''])[0];

pm.test('Khoi phuc quyen thanh cong', function () {
  pm.response.to.have.status(200);
  pm.expect(body).to.include('Đã cập nhật quyền thành công.');
});

pm.test('Quyen thanh vien tro lai USER', function () {
  pm.expect(row).to.include('USER');
});
```

5. Bấm **Save > Send**.
6. Kết quả dự kiến: `200 OK`, `2 PASS`.
7. Kiểm tra cuối trên giao diện: thành viên ID 4 phải là `USER / Hoạt động`.

## 14. Chạy toàn bộ collection

Chỉ chạy sau khi đã tạo lại sản phẩm test và cập nhật `product_id`.

1. Chọn collection trong sidebar.
2. Bấm **Run**.
3. Chọn tab **Functional > Run manually**.
4. Iterations: `1`.
5. Giữ đúng thứ tự:
   1. TC-SP-01
   2. TC-AUTH-01
   3. TC-SP-02
   4. TC-SP-03
   5. TC-SP-04
   6. TC-TV-01
   7. TC-TV-02
   8. TC-TV-03
   9. TC-TV-04
   10. TC-TV-05
6. Trước khi chạy, xóa cookie `PHPSESSID`.
7. Bấm **Start run**.
8. Kết quả của phiên bản hiện tại:
   - Requests: `10`.
   - Assertions: `20`.
   - Passed: `16`.
   - Failed: `4`.
   - Bốn assertion lỗi thuộc TC-TV-02 và TC-TV-03.

## 15. Cách chụp minh chứng

Với mỗi testcase:

1. Chụp phần request gồm tên, method và URL.
2. Chụp tab **Scripts > Post-response**.
3. Sau khi Send, chụp status và tab **Test Results**.
4. Mở giao diện web tương ứng và chụp trạng thái trước/sau.
5. Riêng TC-TV-02 và TC-TV-03, chụp cả response PHP warning và trang thành viên sau khi refresh.
