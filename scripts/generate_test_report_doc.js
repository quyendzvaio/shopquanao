const fs = require("fs");
const path = require("path");

const root = path.resolve(__dirname, "..");
const output = path.join(
  root,
  "reports",
  "Bao_cao_kiem_thu_admin_shop_quan_ao_2026-06-05.doc"
);

function rtfText(value) {
  const text = String(value);
  let result = "";

  for (let i = 0; i < text.length; i += 1) {
    const code = text.charCodeAt(i);
    const char = text[i];

    if (char === "\\" || char === "{" || char === "}") {
      result += `\\${char}`;
    } else if (char === "\n") {
      result += "\\line ";
    } else if (code >= 32 && code <= 126) {
      result += char;
    } else {
      const signed = code > 32767 ? code - 65536 : code;
      result += `\\u${signed}?`;
    }
  }

  return result;
}

function paragraph(text, options = {}) {
  const align = options.align === "center" ? "\\qc" : "\\ql";
  const size = options.size || 20;
  const bold = options.bold ? "\\b" : "";
  const boldOff = options.bold ? "\\b0" : "";
  const color = options.color ? `\\cf${options.color}` : "";
  const spacing = options.spacing || 100;

  return `\\pard${align}\\sa${spacing}\\fs${size}${color}${bold} ${rtfText(text)}${boldOff}\\cf0\\par\n`;
}

function heading(text, level = 1) {
  const size = level === 1 ? 32 : level === 2 ? 27 : 23;
  return paragraph(text, { bold: true, size, color: 1, spacing: 140 });
}

const borders =
  "\\clbrdrt\\brdrs\\brdrw10" +
  "\\clbrdrl\\brdrs\\brdrw10" +
  "\\clbrdrb\\brdrs\\brdrw10" +
  "\\clbrdrr\\brdrs\\brdrw10";

function table(headers, rows, widths) {
  const cellPositions = [];
  let current = 0;
  widths.forEach((width) => {
    current += width;
    cellPositions.push(current);
  });

  const renderRow = (cells, isHeader) => {
    let row = "\\trowd\\trgaph80\\trleft0";
    cellPositions.forEach((position) => {
      row += `${borders}${isHeader ? "\\clcbpat2" : ""}\\clvertalt\\cellx${position}`;
    });
    row += "\n";

    cells.forEach((cell, index) => {
      const alignment = index === 0 ? "\\qc" : "\\ql";
      row += `\\pard\\intbl${alignment}\\fs17${isHeader ? "\\cf3\\b" : "\\cf0"} ${rtfText(cell)}${isHeader ? "\\b0" : ""}\\cell\n`;
    });

    return `${row}\\row\n`;
  };

  let result = renderRow(headers, true);
  rows.forEach((row) => {
    result += renderRow(row, false);
  });
  return `${result}\\pard\\sa100\\par\n`;
}

function image(fileName, caption, targetWidth = 13200) {
  const imagePath = path.join(root, "test-evidence", fileName);
  const data = fs.readFileSync(imagePath);
  const width = data.readUInt32BE(16);
  const height = data.readUInt32BE(20);
  const targetHeight = Math.round((targetWidth * height) / width);
  const hex = data.toString("hex").match(/.{1,128}/g).join("\n");

  return [
    `\\pard\\qc\\sa80{\\pict\\pngblip\\picw${width}\\pich${height}\\picwgoal${targetWidth}\\pichgoal${targetHeight}`,
    hex,
    "}\\par",
    paragraph(caption, { align: "center", size: 17, spacing: 160 }),
  ].join("\n");
}

function evidencePage(title, fileName, caption) {
  return [
    "\\page\n",
    heading(title, 2),
    image(`full/${fileName}`, caption, 12800),
  ].join("\n");
}

const productRows = [
  [
    "1",
    "Chưa đăng nhập; GET /admin/manage_products.php.",
    "Không cho truy cập; HTTP 302 và chuyển đến trang đăng nhập.",
    "ĐẠT. HTTP 302; Location trỏ đến ../login.php. Hai assertion Postman đạt.",
  ],
  [
    "2",
    "Đăng nhập admin; tìm ID 105, QA_DELETE_PRODUCT_20260605_RERUN, giá 123.456đ.",
    "Trang tải thành công và hiển thị đúng sản phẩm trước khi xóa.",
    "ĐẠT. HTTP 200; Postman và giao diện tìm thấy sản phẩm ID 105.",
  ],
  [
    "3",
    "GET /admin/manage_products.php?delete_id=105 bằng session admin.",
    "Xóa sản phẩm; sau redirect không còn trong danh sách và database.",
    "ĐẠT. HTTP 200 sau redirect; không còn tên sản phẩm. Database COUNT(id=105) = 0; giao diện bắt đầu từ ID 99.",
  ],
  [
    "4",
    "Xóa ID không tồn tại: delete_id=999999.",
    "Không phát sinh lỗi server; danh sách vẫn hoạt động.",
    "ĐẠT. HTTP 200 sau redirect; trang hoạt động bình thường và ID 105 không xuất hiện lại.",
  ],
];

const memberRows = [
  [
    "1",
    "Mở danh sách; kiểm tra ID 4, qa_member_20260605.",
    "Hiển thị USER, Hoạt động và không có lỗi PHP.",
    'ĐẠT MỘT PHẦN. Dữ liệu đúng; 3 assertion đạt, nhưng giao diện có warning Undefined array key "user_id" tại dòng 127.',
  ],
  [
    "2",
    "Khóa thành viên: action=lock&id=4.",
    "Trạng thái Đã khóa; redirect và hiển thị thông báo thành công.",
    "KHÔNG ĐẠT. Database đổi sang Đã khóa, nhưng response chỉ có PHP warning, không redirect, không thông báo; 2 assertion thất bại.",
  ],
  [
    "3",
    "Mở khóa thành viên: action=unlock&id=4.",
    "Trạng thái Hoạt động; redirect và hiển thị thông báo thành công.",
    "KHÔNG ĐẠT. Database đổi về Hoạt động, nhưng response chỉ có PHP warning và không redirect; 2 assertion thất bại.",
  ],
  [
    "4",
    "Đổi quyền ID 4 từ USER sang ADMIN: toggle_role=admin.",
    "Quyền ADMIN; redirect và thông báo cập nhật thành công.",
    "ĐẠT CÓ CẢNH BÁO. Hai assertion đạt; quyền và thông báo đúng, nhưng warning user_id vẫn xuất hiện.",
  ],
  [
    "5",
    "Khôi phục quyền ID 4 từ ADMIN về USER: toggle_role=user.",
    "Quyền USER, trạng thái Hoạt động; dữ liệu được khôi phục.",
    "ĐẠT CÓ CẢNH BÁO. Hai assertion đạt; kết quả cuối role=user, status=1, nhưng warning vẫn xuất hiện.",
  ],
];

const documentParts = [
  "{\\rtf1\\ansi\\ansicpg1252\\uc1\\deff0",
  "{\\fonttbl{\\f0 Arial;}{\\f1 Courier New;}}",
  "{\\colortbl;\\red23\\green54\\blue93;\\red47\\green85\\blue151;\\red255\\green255\\blue255;\\red198\\green40\\blue40;\\red8\\green127\\blue35;}",
  "\\landscape\\paperw16838\\paperh11906\\margl720\\margr720\\margt650\\margb650\\f0",
  paragraph("BÁO CÁO KIỂM THỬ CHỨC NĂNG ADMIN", {
    align: "center",
    bold: true,
    size: 42,
    color: 1,
    spacing: 100,
  }),
  paragraph("Project Shop Quần Áo", {
    align: "center",
    bold: true,
    size: 28,
    spacing: 180,
  }),
  table(
    ["STT", "Thông tin", "Nội dung", ""],
    [
      ["1", "Ngày kiểm thử", "05/06/2026, Asia/Ho_Chi_Minh", ""],
      ["2", "Hệ thống", "http://localhost:8080; Docker PHP/Apache và MariaDB", ""],
      ["3", "Tài khoản", "Admin, thông tin đăng nhập do người dùng cung cấp", ""],
      ["4", "Phạm vi", "Xóa sản phẩm; khóa, mở khóa và đổi quyền thành viên", ""],
      ["5", "Dữ liệu tạm", "Sản phẩm ID 105; thành viên ID 4", ""],
    ],
    [700, 2600, 8000, 2000]
  ),
  heading("1. Kết quả Postman", 2),
  table(
    ["STT", "Dữ liệu test", "Kết quả mong muốn", "Kết quả thực tế"],
    [
      [
        "1",
        "Collection: Shop Quan Ao - Kiem thu Admin 2026-06-05; 10 request, 20 assertion.",
        "Tất cả request được thực thi và kết quả được ghi lên Postman Cloud.",
        "10/10 request đã chạy; 16 assertion đạt (80%), 4 assertion không đạt (20%).",
      ],
    ],
    [700, 4300, 4300, 4300]
  ),
  paragraph(
    "Collection: https://go.postman.co/collection/55518061-056fb80d-77ec-493a-9428-c454f607d8d6",
    { size: 17 }
  ),
  paragraph(
    "Postman Cloud Run: https://go.postman.co/workspace/900b3729-79b8-4488-8057-db91c99a46a8/run/55518061-07996d52-0195-4919-93c5-24b2618b99ef",
    { size: 17 }
  ),
  paragraph(
    "Ghi chú: Postman Web bị dừng ở bước Cloudflare trong trình duyệt tự động. Collection được đồng bộ bằng Postman API và chạy bằng Postman CLI cùng tài khoản; kết quả run đã được tải lên Postman Cloud.",
    { size: 18, color: 2 }
  ),
  heading("2. Kịch bản kiểm thử chức năng xóa sản phẩm", 2),
  table(
    ["STT", "Dữ liệu test", "Kết quả mong muốn", "Kết quả thực tế"],
    productRows,
    [700, 4300, 4300, 4300]
  ),
  "\\page\n",
  heading("3. Kịch bản kiểm thử chức năng quản lý thành viên", 2),
  table(
    ["STT", "Dữ liệu test", "Kết quả mong muốn", "Kết quả thực tế"],
    memberRows,
    [700, 4300, 4300, 4300]
  ),
  heading("4. Lỗi được phát hiện", 2),
  paragraph("DEF-ADMIN-USER-01 - Lỗi session user_id làm hỏng redirect khóa/mở khóa", {
    bold: true,
    size: 22,
    color: 4,
  }),
  paragraph(
    'Vị trí: admin/manage_users.php, dòng 19 và 127. Đăng nhập admin không gán $_SESSION["user_id"], nhưng trang quản lý người dùng đọc trực tiếp khóa này.',
    { size: 19 }
  ),
  paragraph(
    'Ảnh hưởng: warning được xuất trước header(), gây lỗi "Cannot modify header information". Database vẫn cập nhật nhưng người dùng không được redirect và không nhận thông báo thành công.',
    { size: 19 }
  ),
  evidencePage(
    "5. Tổng quan collection trên Postman",
    "postman-00-collection-overview.png",
    "Postman collection gồm 10 request; 20 assertion với 16 đạt và 4 không đạt."
  ),
  evidencePage(
    "6. TC-SP-01 - Postman",
    "postman-01-tc-sp-01.png",
    "Cấu hình request, test script và kết quả Postman của ca truy cập khi chưa đăng nhập."
  ),
  evidencePage(
    "6. TC-SP-01 - Giao diện",
    "ui-TC-SP-01-redirected-login.png",
    "Trình duyệt bị chuyển về trang đăng nhập khi truy cập quản lý sản phẩm chưa có session admin."
  ),
  evidencePage(
    "7. TC-AUTH-01 - Postman",
    "postman-02-tc-auth-01.png",
    "Request đăng nhập admin và assertion đăng nhập thành công trên Postman."
  ),
  evidencePage(
    "7. TC-AUTH-01 - Form đăng nhập",
    "ui-TC-AUTH-01-login-form.png",
    "Màn hình form đăng nhập Admin trước khi gửi thông tin."
  ),
  evidencePage(
    "7. TC-AUTH-01 - Sau đăng nhập",
    "ui-TC-AUTH-01-dashboard.png",
    "Dashboard Admin hiển thị sau khi đăng nhập thành công."
  ),
  evidencePage(
    "8. TC-SP-02 - Postman",
    "postman-03-tc-sp-02.png",
    "Postman xác nhận sản phẩm kiểm thử tồn tại trước khi xóa."
  ),
  evidencePage(
    "8. TC-SP-02 - Giao diện",
    "ui-TC-SP-02-product-before-top.png",
    "Sản phẩm ID 105, QA_DELETE_PRODUCT_20260605_RERUN xuất hiện đầu danh sách."
  ),
  evidencePage(
    "9. TC-SP-03 - Postman",
    "postman-04-tc-sp-03.png",
    "Postman gửi xóa ID 105 và hai assertion đều đạt."
  ),
  evidencePage(
    "9. TC-SP-03 - Giao diện",
    "ui-TC-SP-03-product-after-delete.png",
    "Sau khi xóa, ID 105 không còn và danh sách bắt đầu từ ID 99."
  ),
  evidencePage(
    "10. TC-SP-04 - Postman",
    "postman-05-tc-sp-04.png",
    "Postman thử xóa ID 999999 không tồn tại; hai assertion đều đạt."
  ),
  evidencePage(
    "10. TC-SP-04 - Giao diện",
    "ui-TC-SP-04-delete-nonexistent.png",
    "Giao diện quản lý sản phẩm vẫn hoạt động sau khi xóa ID không tồn tại."
  ),
  evidencePage(
    "11. TC-TV-01 - Postman",
    "postman-06-tc-tv-01.png",
    "Postman xác nhận thành viên ID 4 tồn tại, quyền USER và trạng thái Hoạt động."
  ),
  evidencePage(
    "11. TC-TV-01 - Giao diện",
    "ui-TC-TV-01-member-initial.png",
    "Thành viên ID 4 ở trạng thái USER / Hoạt động; giao diện đồng thời lộ warning user_id."
  ),
  evidencePage(
    "12. TC-TV-02 - Postman",
    "postman-07-tc-tv-02.png",
    "Hai assertion khóa thành viên thất bại do response chứa PHP warning và không redirect."
  ),
  evidencePage(
    "12. TC-TV-02 - Response giao diện",
    "ui-TC-TV-02-lock-response-error.png",
    "Response trực tiếp khi khóa chứa Undefined array key user_id và Cannot modify header information."
  ),
  evidencePage(
    "12. TC-TV-02 - Trạng thái dữ liệu",
    "ui-TC-TV-02-member-locked.png",
    "Mở lại danh sách cho thấy thành viên đã bị khóa dù response thao tác bị lỗi."
  ),
  evidencePage(
    "13. TC-TV-03 - Postman",
    "postman-08-tc-tv-03.png",
    "Hai assertion mở khóa thất bại do cùng lỗi session và redirect."
  ),
  evidencePage(
    "13. TC-TV-03 - Response giao diện",
    "ui-TC-TV-03-unlock-response-error.png",
    "Response trực tiếp khi mở khóa tiếp tục hiển thị hai PHP warning."
  ),
  evidencePage(
    "13. TC-TV-03 - Trạng thái dữ liệu",
    "ui-TC-TV-03-member-unlocked.png",
    "Mở lại danh sách cho thấy thành viên đã trở về trạng thái Hoạt động."
  ),
  evidencePage(
    "14. TC-TV-04 - Postman",
    "postman-09-tc-tv-04.png",
    "Postman xác nhận đổi quyền USER sang ADMIN thành công."
  ),
  evidencePage(
    "14. TC-TV-04 - Giao diện",
    "ui-TC-TV-04-member-admin.png",
    "Giao diện hiển thị quyền ADMIN và thông báo cập nhật thành công, nhưng vẫn có warning user_id."
  ),
  evidencePage(
    "15. TC-TV-05 - Postman",
    "postman-10-tc-tv-05.png",
    "Postman xác nhận khôi phục quyền ADMIN về USER thành công."
  ),
  evidencePage(
    "15. TC-TV-05 - Giao diện",
    "ui-TC-TV-05-member-restored.png",
    "Trạng thái cuối: thành viên ID 4 là USER / Hoạt động."
  ),
  "\\page\n",
  heading("16. Kết luận", 2),
  paragraph("Xóa sản phẩm: đạt toàn bộ các ca kiểm thử đã thực hiện.", {
    bold: true,
    size: 20,
    color: 5,
  }),
  paragraph(
    "Quản lý thành viên: thay đổi dữ liệu hoạt động, nhưng khóa/mở khóa không đạt về response và trải nghiệm giao diện do thiếu session user_id. Đổi quyền hoạt động nhưng trang vẫn hiển thị PHP warning.",
    { size: 19 }
  ),
  paragraph(
    "Trạng thái dữ liệu cuối: sản phẩm ID 105 đã bị xóa; thành viên ID 4 ở quyền USER và trạng thái Hoạt động.",
    { size: 19 }
  ),
  "}",
];

fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, documentParts.join("\n"), "utf8");
console.log(output);
