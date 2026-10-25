# Spec: Gỡ Stylitics/FindMine, tái tổ chức codebase, UC-A phối set theo tag, UC-C gợi ý free ship

- Ngày: 2026-10-15
- Trạng thái: CHỜ DUYỆT
- Nhánh: `feature/outfit-tags-freeship` (tách từ `develop`)

## 1. Bối cảnh và mục tiêu

Dự án là đồ án/demo: website bán quần áo PHP 8.2 + MariaDB, kèm chatbot RAG deterministic. Hai use case cũ phụ thuộc nhà cung cấp ngoài (UC1 dùng Stylitics/FindMine, UC2 dùng chuỗi sự kiện giỏ hàng) và không triển khai thật được, vì Stylitics live đang bị chặn bởi vendor.

Mục tiêu:

1. Gỡ hẳn Stylitics, FindMine, UC1, UC2 cùng chuỗi sự kiện outbox → Redis → worker.
2. Xoá toàn bộ eval cũ, thay bằng một bộ eval nhỏ cho use case mới.
3. Tái tổ chức codebase của cả website lẫn chatbot cho gọn, tách mã nguồn ra khỏi webroot.
4. Thêm **UC-A**: phối set theo dịp/phong cách (tag), ngân sách và size, chỉ dùng catalog của shop.
5. Thêm **UC-C**: gợi ý thêm món để giỏ hàng đạt ngưỡng miễn phí ship.

Tiêu chí thành công: chatbot demo được A và C từ đầu đến cuối; 0 sản phẩm bịa; mọi set của A ≤ ngân sách và còn hàng; mọi gợi ý của C đưa giỏ vượt ngưỡng; tất cả cổng CI đều xanh.

### Quyết định đã chốt với người dùng

| Quyết định | Nội dung |
| --- | --- |
| Nguồn quy tắc phối đồ | Mỗi sản phẩm có 1–3 tag dịp/phong cách, lưu trong **1 cột** `products.style_tags`. Tag được gắn **một lần, offline** (bởi Claude, dựa trên tên + mô tả) và nằm cố định trong migration. Lúc chạy, LLM không gắn tag. |
| Bảng mới | Không tạo bảng mới. |
| Bảng cũ | Xoá bằng migration `DROP` mới; không sửa migration đã chạy. |
| Checkout | UC-C không đụng checkout; ngưỡng chỉ nằm trong config. |
| Eval cũ | Xoá hết. |

## 2. Ngoài phạm vi

- Tính phí ship thật trong checkout.
- Phân loại theo giới tính (dữ liệu sản phẩm không có giới tính).
- Phối nhiều lớp (áo trong + áo khoác); áo khoác được coi là "áo".
- Đổi sang namespace PSR-4 (giữ classmap, xem §4.3).
- Viết lại logic SQL bên trong các trang storefront cũ; chỉ dời file và dùng bootstrap chung.
- Học quy tắc phối từ lịch sử mua chung (ghi vào "hướng phát triển").

## 3. Phần 1: Gỡ bỏ

### 3.1 Xoá

- **Toàn bộ thư mục `api/services/Fashion/`** (60 file). Đã kiểm chứng: ngoài chính thư mục này, nó chỉ được tham chiếu từ `ToolRegistry.php` (43 chỗ, đều nằm trong luồng phối đồ cũ), `ChatbotService.php` và `internal/mcp.php` (proactive UC2).
- Script: `consume_fashion_events.php`, `publish_fashion_outbox.php`, `findmine_live_inspect.php`, `import_fashion_provider_mappings.php`, `run_fashion_extraction_eval.php`, `run_findmine_agent_eval.php`, `run_findmine_offline_eval.php`, `run_stylitics_agent_eval.php`, `smoke_cart_event_pipeline.php`, `smoke_findmine_*.php`, `smoke_proactive_*.php`, `smoke_proactive_ws_turn.mjs`, `eval_rag_chatbot.py`.
- **Toàn bộ thư mục `eval/`** (bộ eval mới viết lại ở §7).
- `docker/findmine-mcp-shopquanao.patch`.
- Docs: `docs/cart-styling-event-architecture.md`, `docs/fashion-extraction-*.md`, `docs/catalog-variant-*.md`.
- Hai service compose `fashion-outbox-publisher` và `fashion-event-consumer`, cùng các dòng `COPY` của chúng trong `Dockerfile`.
- Toàn bộ biến môi trường `STYLITICS_*`, `FINDMINE_*`, `FASHION_PROVIDER`, `FASHION_EVENT_*`, `STYLING_PROVIDER` trong `.env.example`, `docker-compose.yml` và bước deploy của `ci.yml`.

### 3.2 Sửa

- `CartService`: bỏ `publishCartItemAdded`; thêm vào giỏ chỉ còn ghi bảng `cart`.
- Chatbot: bỏ intent `suggest_complementary_products`, `unsupported_outfit` cũ và luồng proactive UC2 khỏi `DeterministicIntentParser`, `LlmInputParser`, `ToolPlanner`, `PlanValidator`, `CapabilityRegistry`, `EvidenceNormalizer`, `LightweightEvidenceScorer`, `ResponseGenerator`, `ChatbotService`, `ToolRegistry`, `ToolDefinitionCatalog`, `config/chatbot_tools.php`, `internal/mcp.php`.
- MCP `mcp-server/src/{tools,shop-client,chat-stream}.ts`: bỏ tool và payload cũ.
- `ProductService`, `ToolApplicationService`, Langfuse (`LangfuseTraceOutbox`, `LangfuseOtlpTracePublisher`): bỏ các trường chỉ dành cho nhà cung cấp. **Giữ** cơ chế Langfuse trace outbox.
- Test: `ChatbotGuardrailTest`, `ManifestPlannerTest`, `tests/bootstrap.php`.

### 3.3 Migration `database/migrations/2026_10_15_drop_styling_providers.sql`

`DROP TABLE IF EXISTS` đúng 4 bảng do các migration cũ tạo ra: `fashion_provider_product_mapping`, `proactive_styling_state`, `fashion_event_outbox`, `fashion_consumed_events`. Khi `sql/` được dời thành `database/` (§4), migration này nằm ở `database/migrations/`; tên các migration đã chạy giữ nguyên để bảng `_migrations` không chạy lại chúng. Bảng `outfit_suggestions` và endpoint `GET /api/outfit` (tính năng website độc lập, 5 cặp phối tĩnh) được **giữ nguyên**, vì chúng không thuộc chatbot.

## 4. Phần 2: Tái tổ chức codebase

### 4.1 Vấn đề hiện tại

- Webroot là gốc repo, nên `sql/`, `config/`, `scripts/`… có thể bị truy cập qua HTTP.
- 13 trang storefront nằm lẫn với file cấu hình ở thư mục gốc.
- Class chatbot nằm trong `api/controllers/chatbot/`, lẫn với các file controller dạng thủ tục.
- 326 lệnh `require_once` thủ công; `tests/bootstrap.php` phải require từng file.
- `ToolRegistry.php` dài 964 dòng, gom handler của mọi tool vào một chỗ.

### 4.2 Cấu trúc đích

```
public/                         # webroot DUY NHẤT
  index.php product.php cart.php checkout.php ... (13 trang storefront, URL giữ nguyên)
  admin/*.php                   # 8 trang admin, URL giữ nguyên
  api/index.php                 # front controller REST (mỏng)
  assets/css/style.css
  assets/images/*
src/
  bootstrap.php                 # autoload + config + session, dùng chung cho trang, API, script
  Http/
    routes.php                  # bảng route tách khỏi api/index.php
    middleware.php
    Controllers/<Auth|Cart|Products|Orders|Categories|Faq|SizeGuide|Knowledge|Outfit|Admin|Chatbot|Internal>/*.php
  Shop/                         # nghiệp vụ website
    ProductService.php CartService.php OrderService.php SizeService.php
    Catalog/*
  Chatbot/
    ChatbotService.php ChatbotSessionContext.php
    Pipeline/ Llm/ Contracts/ Memory/ Knowledge/
    Tools/                      # ToolRegistry đã tách theo miền, gateway, catalog tool
      Handlers/<Product|Size|Policy|Order|Outfit|FreeShipping>ToolHandler.php
    Outfit/                     # UC-A
    FreeShipping/               # UC-C
  Infrastructure/
    Cache/ Observability/ Database/
templates/partials/             # header.php footer.php chatbox.php (từ includes/)
config/                         # db.php chatbot_tools.php
database/
  schema.sql                    # từ sql/shop_db.sql
  migrations/                   # từ sql/migrations/
scripts/                        # chỉ script vận hành: migrations, ingest_knowledge, publish_langfuse_trace_outbox, run_agent_eval
eval/                           # bộ eval mới (§7)
mcp-server/  docker/  knowledge/  tests/  docs/   # giữ nguyên vị trí
```

### 4.3 Quy tắc dời file

- Dùng `git mv` để giữ lịch sử. Mỗi bước dời là một commit riêng, không đổi hành vi.
- **Autoload:** classmap của Composer quét `src/`. Dockerfile thêm stage `composer:2` để chạy `composer dump-autoload --no-dev --classmap-authoritative` rồi chép `vendor/` sang ảnh runtime. `src/bootstrap.php` require `vendor/autoload.php`. Mọi `require_once` trỏ tới class đều bị xoá; chỉ giữ require cho file thủ tục (controller, template, config).
- **Không dùng namespace.** Tên class hiện tại đã đủ cụ thể; chuyển sang PSR-4 tốn nhiều công sửa mà lợi ích cho demo thấp.
- Webroot: `NGINX_WEBROOT=/var/www/html/public`. `docker/app-nginx-api.conf` trỏ tới `$document_root/api/index.php`. `docker/apache.conf` đổi `DocumentRoot`. Dockerfile chỉ chép `public/ src/ templates/ config/ database/ knowledge/ scripts/` và `vendor/`.
- Trang storefront đổi `require_once 'config/db.php'` thành `require_once __DIR__ . '/../src/bootstrap.php'`, và `include 'includes/header.php'` thành đường dẫn tới `templates/partials/`. Đường dẫn ảnh/CSS đổi sang `/assets/...`.
- **Migration ra khỏi đường xử lý request:** hiện `config/db.php` gọi `runMigrations()` (định nghĩa trong `sql/migrations/001_init.php`) ở **mọi request**, còn `scripts/run_database_migrations.php` chỉ include `db.php` rồi in "up to date". Sau khi sửa: logic runner nằm ở `src/Infrastructure/Database/MigrationRunner.php` và chỉ được gọi từ `scripts/run_database_migrations.php`; `config/db.php` chỉ tạo PDO. Cách theo dõi bằng `_migrations` (theo `basename`) và advisory lock giữ nguyên, nên các migration đã chạy không chạy lại. `001_init.php` (vốn chỉ chứa hàm `runMigrations`) bị xoá sau khi chuyển logic sang `MigrationRunner`. `003_admin_user.php` hiện không được ai gọi (runner chỉ quét `*.sql`), nên dời thành script vận hành tuỳ chọn `scripts/ensure_admin_user.php` mà không đổi hành vi. Thư mục `database/migrations/` từ đây chỉ chứa file `.sql`. Compose và CI vốn đã chạy bước migration trước app (`deployment.md`), giờ bước đó mới thực sự có tác dụng.
- `ToolRegistry` tách thành các `*ToolHandler` theo miền; `ToolRegistry` chỉ còn phân phối lời gọi tới handler. Tên tool và hợp đồng MCP không đổi (trừ tool mới/bỏ ở §3, §5, §6).
- Cập nhật mọi đường dẫn trong `composer.json` (classmap, script `stan`), `phpstan.neon`, `phpunit.xml`, `ci.yml`, `docker-compose.yml`, `scripts/run_database_migrations.php`, `.dockerignore`, `CLAUDE.md`, `.claude/rules/*.md`, `README.md`.

### 4.4 Tiêu chí xong của phần 2

- `curl /sql/shop_db.sql`, `/config/db.php`, `/src/bootstrap.php` đều trả 404 hoặc không lộ nội dung.
- Mọi URL storefront, admin và `/api/*` cũ vẫn chạy (smoke test thủ công + `curl` trong §8).
- Unit test, PHPStan, PHPCS, MCP test/build, `docker compose config` và docker build đều xanh.

## 5. Phần 3: UC-A, phối set theo tag

### 5.1 Dữ liệu

Migration `2026_10_15_product_style_tags.sql`:

- `ALTER TABLE products ADD COLUMN style_tags VARCHAR(100) NOT NULL DEFAULT ''`. Giá trị là các tag nối bằng dấu phẩy, không có dấu `#`; ví dụ `congso,phongvan`.
- 50 câu `UPDATE` gán 1–3 tag cho từng sản phẩm hiện có, do Claude soạn từ tên + mô tả và người dùng duyệt trong PR.

**Bộ tag cố định** (mã → nghĩa → từ khoá nhận diện chính):

| Mã | Nghĩa | Từ khoá trong câu chat |
| --- | --- | --- |
| `congso` | Công sở | công sở, đi làm, văn phòng |
| `phongvan` | Phỏng vấn | phỏng vấn, interview |
| `dichoi` | Đi chơi, dạo phố | đi chơi, dạo phố, cà phê |
| `dibien` | Đi biển, du lịch hè | đi biển, du lịch, mùa hè |
| `hendo` | Hẹn hò | hẹn hò, date |
| `dutiec` | Dự tiệc | dự tiệc, đám cưới, sự kiện |
| `thethao` | Thể thao, vận động | thể thao, tập gym, chạy bộ |
| `streetstyle` | Đường phố | streetstyle, đường phố, cá tính |
| `basic` | Tối giản, dễ phối | basic, tối giản, đơn giản |
| `mualanh` | Mùa lạnh | mùa đông, trời lạnh, giữ ấm |

Bộ tag nằm ở **một chỗ duy nhất** (`src/Chatbot/Outfit/StyleTag.php`) và được dùng chung cho parser, validator và migration test.

Admin: form thêm/sửa sản phẩm có thêm ô chọn tag (checkbox theo bộ tag cố định, tối đa 3). Có ô này thì sản phẩm mới mới phối được.

### 5.2 Vị trí của sản phẩm trong set

`OutfitSlotResolver` xác định vị trí theo danh mục, vì `product_subcategories` hiện chỉ có dữ liệu cho giày, không dùng được:

| Danh mục | Vị trí |
| --- | --- |
| 1 Áo | `top` |
| 2 Quần | `bottom` |
| 3 Váy & Đầm, tên bắt đầu bằng "Chân váy" | `bottom` |
| 3 Váy & Đầm, còn lại | `dress` |
| 4 Phụ kiện | `accessory` |

### 5.3 Đầu vào

`OutfitRequest { tags: string[1..2], budget: ?int, height_cm: ?int, weight_kg: ?int, size: ?string, anchor_product_id: ?int }`

- `DeterministicIntentParser` nhận diện intent `build_outfit` (giữ lại regex phối đồ hiện có), bắt tag theo bảng từ khoá, bắt ngân sách theo quy ước hiện có (`800k` → 800000) và số đo/size.
- LLM (`LlmInputParser`) chỉ bổ sung tag khi parser không bắt được, và **chỉ được chọn trong bộ tag cố định**; giá trị ngoài bộ tag bị loại.
- Không có tag và không có sản phẩm neo: trả câu hỏi lại, liệt kê các dịp hỗ trợ.
- Có `anchor_product_id` ("áo mã 50 phối với gì"): tag yêu cầu là tag của sản phẩm neo, và sản phẩm neo cố định ở vị trí của nó.

### 5.4 Thuật toán `OutfitBuilder`

1. **Ứng viên:** sản phẩm có ít nhất một tag yêu cầu và còn hàng. Nếu biết size (từ số đo qua `SizeService`, hoặc size khách nêu, hoặc size trong bộ nhớ dài hạn) thì phải còn biến thể đúng size có `stock > 0`; nếu không biết size thì chỉ cần `stock > 0`.
2. **Khung set:** `top + bottom` hoặc `dress`; được thêm tối đa 1 `accessory`.
3. **Liệt kê** mọi tổ hợp của từng khung (catalog 50 sản phẩm nên liệt kê hết được; giới hạn cứng 5.000 tổ hợp để an toàn). Loại tổ hợp có tổng giá > ngân sách.
4. **Màu:** màu lấy từ biến thể; không có thì suy từ tên theo bảng `colors`. Trung tính = {đen, trắng, xám, be, navy, kaki, nâu}. Loại set có hơn 1 món màu nổi.
5. **Điểm:** `Σ số tag yêu cầu khớp trên từng món` × 10 + `số tag chung giữa các món` × 3 + `số món trung tính`; phụ kiện chỉ được thêm khi nó tăng điểm và vẫn ≤ ngân sách. Hoà điểm thì chọn tổng giá thấp hơn, rồi tới `product_id` nhỏ hơn (để kết quả luôn giống nhau).
6. **Kết quả:** tối đa 2 set; set thứ hai phải khác set đầu ít nhất 1 món chính. Mỗi set kèm tổng giá, size đề xuất từng món và lý do (tag khớp, màu).
7. **Không có set nào:** báo lý do cụ thể (ngân sách quá thấp và giá set rẻ nhất là bao nhiêu / hết size / không có sản phẩm cho dịp đó).

### 5.5 Tích hợp

- Tool mới `build_outfit`: định nghĩa trong `config/chatbot_tools.php`, `ToolDefinitionCatalog` và MCP `tools.ts`; handler `OutfitToolHandler`; endpoint nội bộ `/api/internal/mcp` gọi qua `ToolApplicationService`.
- Set đi qua `EvidenceNormalizer` + `ProductConstraintVerifier` như các tool sản phẩm khác; `ResponseGenerator` chỉ viết lời giới thiệu từ set đã kiểm.
- `chat-stream` gửi thẻ sản phẩm theo allow-list hiện có, kèm nhóm set (`outfit_index`) và tổng giá.

## 6. Phần 4: UC-C, gợi ý free ship

### 6.1 Cấu hình

- `FREE_SHIPPING_THRESHOLD` (mặc định `500000`, khớp `knowledge/policies.md`).
- `FREESHIP_NUDGE_MAX_GAP` (mặc định `200000`): chỉ gợi ý chủ động khi giỏ còn thiếu ≤ mức này.

### 6.2 Chọn món (`FreeShippingGapAdvisor`)

Đầu vào: giỏ hàng của user (món, số lượng, tạm tính), size đã biết (nếu có).

1. `gap = threshold - subtotal`. Nếu `gap <= 0` thì giỏ đã được free ship; nếu giỏ rỗng thì chỉ thông báo ngưỡng.
2. **Ứng viên:** còn hàng (đúng size nếu biết), không nằm trong giỏ, `price >= gap`.
3. **Xếp hạng:** `price - gap` tăng dần (sát số tiền còn thiếu nhất), rồi số tag chung với món trong giỏ giảm dần, rồi `product_id`.
4. Trả về 3 món đầu (chế độ hỏi thẳng) hoặc 1 món đầu (chế độ chủ động). Không có ứng viên thì không gợi ý.

### 6.3 Kích hoạt

- **Hỏi thẳng:** intent mới `freeship_gap` ("mua thêm gì để được free ship", "còn thiếu bao nhiêu để miễn phí ship"). Gọi tool `suggest_freeship_items`. User chưa đăng nhập thì trả ngưỡng từ chính sách và mời đăng nhập.
- **Chủ động:** sau khi câu trả lời chính đã được kiểm tra, `ChatbotService` gọi `FreeShippingNudge` nếu thoả **tất cả** các điều kiện:
  - user đã đăng nhập và giỏ không rỗng;
  - `0 < gap <= FREESHIP_NUDGE_MAX_GAP`;
  - intent của lượt thuộc nhóm mua sắm: tìm/xem sản phẩm, `build_outfit`, hỏi giỏ hàng, hỏi phí ship/đơn hàng;
  - `response_type` không phải fallback, hỏi lại hay từ chối;
  - "dấu vân tay" giỏ hiện tại (hash của danh sách `product_id`/`variant_id`/số lượng) khác giá trị đã lưu trong `chat_session_memory.slots.freeship_nudged_fingerprint`.
- Khi đủ điều kiện: nối 1 câu ngắn + 1 thẻ sản phẩm vào cuối câu trả lời, rồi lưu dấu vân tay. Giỏ chưa đổi thì không gợi ý lại.
- Bot **không bao giờ** tự thêm vào giỏ hay checkout.

## 7. Eval mới

- `eval/cases.jsonl`, khoảng 40 câu: 15 câu A (nhiều dịp, ngân sách, size, sản phẩm neo, ngân sách quá thấp, dịp lạ), 10 câu C (hỏi thẳng, chủ động, đã đủ ngưỡng, giỏ rỗng, chưa đăng nhập, không gợi ý lặp), 8 câu phải từ chối (tự checkout, thanh toán…), 7 câu không liên quan.
- `scripts/run_agent_eval.php`: chạy deterministic ở tầng intent + tool (không gọi LLM), đầu ra JSON vào `reports/eval/` (gitignored). Kiểm: intent đúng, tool đúng, 0 sản phẩm ngoài catalog, set ≤ ngân sách, còn hàng đúng size, gợi ý C vượt ngưỡng, gợi ý chủ động không lặp.
- CI: thay bước `run_findmine_offline_eval.php` bằng `run_agent_eval.php` (phải PASS 100%); bỏ kiểm cú pháp Python cho các file eval cũ.

## 8. Kiểm thử

- **Unit, mỗi thành phần mới có test:** `StyleTag`, `StyleTagParser`, `OutfitSlotResolver`, `OutfitBuilder` (ngân sách, size, tồn kho, màu, hoà điểm, sản phẩm neo, không có set), `FreeShippingGapAdvisor`, `FreeShippingNudge` (từng điều kiện kích hoạt, chống lặp), migration tag (mọi sản phẩm có 1–3 tag thuộc bộ cố định — test đọc file migration).
- **Test cũ:** sửa cho khớp intent/tool mới, không giảm độ phủ guardrail.
- **Integration (cần DB `shop_test`):** `OutfitBuilder` và `FreeShippingGapAdvisor` chạy trên schema + migration thật.
- **MCP:** test hợp đồng cho `build_outfit` và `suggest_freeship_items`.
- **Smoke sau tái tổ chức:** `curl` trang chủ, `/product.php?id=50`, `/admin/login.php`, `/api/products?limit=1`, `/nginx-health`; xác nhận 404 cho `/sql/...`, `/config/...`, `/src/...`.

## 9. Thứ tự triển khai

Bốn giai đoạn, mỗi giai đoạn là một nhóm commit và phải xanh đủ cổng trước khi sang giai đoạn sau:

1. **Gỡ bỏ** (§3) cùng xoá eval cũ.
2. **Tái tổ chức** (§4). Làm trước tính năng mới để code mới nằm đúng chỗ ngay từ đầu.
3. **UC-A** (§5).
4. **UC-C** (§6) cùng eval mới (§7) và cập nhật tài liệu (`README.md`, `CLAUDE.md`, `.claude/rules/`).

## 10. Rủi ro

| Rủi ro | Giảm thiểu |
| --- | --- |
| Dời file làm vỡ đường dẫn trang cũ (không có test) | Smoke `curl` từng trang sau giai đoạn 2; `php -l` toàn bộ; grep các đường dẫn tương đối còn sót |
| Autoload classmap thiếu class lúc chạy | `--classmap-authoritative` + test khởi tạo `ChatbotService` và từng handler |
| Tag do Claude gắn chưa sát | Bảng tag nằm trong migration, người dùng duyệt trong PR; sửa bằng migration mới |
| Dữ liệu biến thể/size thiếu cho một số sản phẩm | Không có biến thể thì dùng `products.stock`; size không suy được thì báo "chưa xác định size" |
| Bỏ tự migrate trong request: môi trường dev/test quên chạy migration | `README` và `CLAUDE.md` ghi rõ lệnh; integration test bootstrap gọi `MigrationRunner` trên `shop_test`; app báo lỗi rõ ràng khi thiếu cột `style_tags` |
| Xoá bảng mất dữ liệu | Chỉ là dữ liệu demo/eval; đã được người dùng chấp thuận |
