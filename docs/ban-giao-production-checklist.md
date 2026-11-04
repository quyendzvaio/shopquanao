# Checklist bàn giao production - Fashion Shop

Cập nhật: 2026-11-05. Các mục "chưa kiểm chứng" bên dưới đến từ phiên làm việc hiện tại, cần xác nhận lại trên máy thật.

## 1. Mốc và nguyên tắc

- **Hạn bàn giao: trước 01:00 ngày 2026-11-10** (mục "Delivery deadline" trong `CLAUDE.md`, thêm ở #43).
- Không bàn giao nếu thiếu một trong các cổng sau:
  - [ ] `scripts/dev/gate.sh` xanh (autoload, Unit, PHPStan, integration, mcp-server, chat-graph, snapshot diff).
  - [ ] Eval LLM thật `npm --prefix chat-graph run eval` đạt >= 90% (exit code 0), chạy trên stack có model production.
  - [ ] CI xanh trên `master` (Code Quality, Unit, Integration, Security, Docker Build, Deploy to Production).
  - [ ] Smoke sau deploy xanh: `BASE_URL=http://<host> scripts/dev/smoke.sh` (không dòng `FAIL`).
- Nguyên tắc: không `docker compose down` thủ công trên production có dữ liệu cần giữ (job deploy tự chạy `down --remove-orphans` nhưng không xoá volume). Không in secret ra log hay chat.

## 2. Chặn bàn giao (blocking)

### 2.1 Deploy SSH thất bại (chặn số 1)

4 lần deploy production gần nhất (v1.6.4 -> v1.9) đều lỗi ở bước GitHub Actions "Deploy via SSH": `dial tcp <host>:22: i/o timeout`. Runner không kết nối được host production qua cổng 22. Cho đến khi sửa, production vẫn chạy bản cũ hơn v1.6.4: không có answer streaming, không có bản vá DSML tool-call, không có bản vá grounding order-line.

- [ ] Host production đang chạy và ping/SSH được từ ngoài.
- [ ] Firewall / security group mở cổng 22 (hoặc `DEPLOY_PORT`) cho dải IP runner GitHub (hoặc dùng self-hosted runner).
- [ ] Secret đúng: `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_PATH` (đường dẫn tuyệt đối), `DEPLOY_GITHUB_TOKEN` (hoặc `GITHUB_TOKEN`).
- [ ] Re-run workflow trên `master`, bước "Deploy via SSH" xanh và in `Deploy complete!`.
- [ ] Xác nhận production đã chạy bản mới (commit in ra `Repo: <sha>` khớp `master`; chat hiển thị streaming).

### 2.2 Cấu hình LLM

- [ ] `LLM_MODEL=deepseek-v4-flash-sale`. Model cũ `gpt-oss-120b-free` đã bị nhà cung cấp gỡ; dùng nó thì chatbot chỉ trả câu cố định "đang bận".
- Lưu ý: workflow ghi `.env` từ GitHub secrets mỗi lần deploy (mặc định `LLM_MODEL=deepseek-chat`, `LLM_BASE_URL=https://api.deepseek.com`, `LLM_TIMEOUT=25` nếu secret trống). Phải đặt đúng secret `LLM_MODEL`, `LLM_BASE_URL`, `LLM_API_KEY`; sửa tay `.env` trên host sẽ bị ghi đè ở lần deploy sau.

### 2.3 App treo sau khi reboot host

App container có thể treo sau reboot (php-fpm chậm, nginx không bao giờ khởi động). Đã sửa trong PR chờ merge "fix(app): keep polling php-fpm readiness".

- [ ] PR đã merge vào `master` và đã deploy.
- [ ] Reboot test trên production đạt (xem mục 5).

## 3. Cấu hình production (`.env`)

`.env` trên host do job deploy sinh từ secrets. Chỉ kiểm tên biến và độ dài/trạng thái, không in giá trị.

| Biến | Giá trị / quy tắc bắt buộc | Cách kiểm không lộ secret |
| --- | --- | --- |
| `APP_ENV` | `production` (workflow tự ghi; chat-graph chỉ trace khi production) | `grep -c '^APP_ENV=production$' .env` phải ra 1 |
| `LLM_MODEL` | `deepseek-v4-flash-sale` | `grep '^LLM_MODEL=' .env` (không phải secret) |
| `LLM_BASE_URL` | Endpoint của nhà cung cấp hiện dùng (kiraai.vn hoặc tương đương) | `grep '^LLM_BASE_URL=' .env` |
| `LLM_API_KEY` | Không rỗng, key còn hạn | `grep -c '^LLM_API_KEY=.' .env` = 1; thử: `docker compose exec -T chat-graph sh -c 'wget -qO- --timeout=8 "$LLM_BASE_URL/models" >/dev/null && echo ok'` (401 cũng chứng minh kết nối được) |
| `LLM_TIMEOUT` | Mặc định 25 s; cân nhắc tăng nếu provider chậm (mục 5) | `grep '^LLM_TIMEOUT=' .env` |
| `CHAT_GRAPH_TOKEN` | Chuỗi ngẫu nhiên dài (>= 32 ký tự), khác `change-me` và khác default `local-dev-...`; `app` và `chat-graph` dùng chung | `awk -F= '/^CHAT_GRAPH_TOKEN=/{print length($2)}' .env` |
| `MCP_SERVICE_TOKEN` | Chuỗi ngẫu nhiên dài (>= 32 ký tự), không rỗng | `awk -F= '/^MCP_SERVICE_TOKEN=/{print length($2)}' .env` |
| `DB_PASS`, `MARIADB_ROOT_PASSWORD` | Mạnh, không phải mặc định | `grep -c '^DB_PASS=.' .env` |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Tài khoản admin env: đặt mật khẩu mạnh, đổi mật khẩu mặc định | Đăng nhập `/admin/login.php` bằng mật khẩu mới; mật khẩu cũ phải bị từ chối |
| `PMA_PASSWORD` / phpMyAdmin | Không mở ra Internet; nếu bật phải có mật khẩu | `docker compose ps` xem phpmyadmin có chạy không; `curl -s -o /dev/null -w '%{http_code}' http://<host>:<port-pma>` từ ngoài |
| `CHATBOT_ALLOWED_ORIGINS` | Chỉ domain production (hoặc trống nếu cùng origin) | `grep '^CHATBOT_ALLOWED_ORIGINS=' .env` |
| `NGINX_HTTP_PORT` | Bỏ trống hoặc 80 trên production | `grep '^NGINX_HTTP_PORT=' .env` |
| `LANGFUSE_*` (nếu bật observability) | `LANGFUSE_ENABLED=true` kèm public/secret key; mọi giá trị `local-only-change-me` phải được thay (Postgres, Redis, ClickHouse, MinIO, SALT, ENCRYPTION_KEY `openssl rand -hex 32`, NEXTAUTH_SECRET, INIT_USER_PASSWORD) | `grep -c 'local-only-change-me' .env` phải ra 0; `LANGFUSE_TRACE_CONTENT=false` trừ khi cố ý |

- [ ] Nếu không dùng Langfuse: `LANGFUSE_ENABLED=false` và không có `COMPOSE_PROFILES=observability`.
- [ ] `.env` không nằm trong git (`git check-ignore .env`).
- [ ] Mọi secret trên đã được đặt trong GitHub `production` environment (không chỉ sửa tay `.env`).

## 4. Quy trình deploy và kiểm tra sau deploy

Job "Deploy to Production" chạy khi push `main`/`master`, sau khi `unit-tests`, `integration-tests`, `security`, `docker` xanh. Các bước trên host qua SSH:

1. Cài SSH key; kiểm tra `GITHUB_TOKEN` và `DEPLOY_PATH` (tuyệt đối), cài Docker nếu thiếu.
2. Dọn đĩa; clone/pull, `git reset --hard origin/<default branch>`.
3. Ghi `.env` từ secrets (mục 3); bật profile observability nếu `LANGFUSE_ENABLED=true`.
4. `docker compose down --remove-orphans`, dọn image/builder (không xoá volume); yêu cầu còn trống >= 6000 MB.
5. Build `reranker`, `rag-ml`, `chat-graph`, `app`; pull `nginx db redis qdrant`.
6. `docker compose up -d --wait db redis`, rồi migration gate: `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`.
7. `docker compose up -d --remove-orphans`.
8. Tự kiểm (fail job nếu lỗi): `/api/products?limit=1` = 200 (chờ tối đa 300 s); chat-graph `/health` có `"ok":true`; index knowledge vào Qdrant (`scripts/ingest_knowledge.php`, thử 10 lần); `POST /api/chatbot` "thêm áo này vào giỏ" trả `response_type":"refusal"`; `POST /api/chatbot/stream` có `event: chat.complete`.

Checklist thực hiện:

- [ ] Push/merge vào `master`, mở tab Actions, theo dõi tới bước "Deploy complete".
- [ ] Nếu job lỗi: đọc log cuối (job đã in `docker compose logs app/rag-ml/chat-graph --tail`), sửa rồi re-run; không sửa tay rồi bỏ qua CI.
- [ ] Trên host: `docker compose ps` mọi service Up/healthy (nginx, app, chat-graph, db, redis, qdrant, rag-ml, reranker).
- [ ] Smoke đầy đủ từ máy ngoài: `BASE_URL=http://<host> scripts/dev/smoke.sh` (gồm 403/404 cho `/sql/shop_db.sql`, `/config/db.php`, `/src/bootstrap.php`, `/scripts/...`, `/templates/...`, `/api/internal/mcp`; WebSocket không upgrade).
- [ ] Tối thiểu: `curl -fsS http://<host>/nginx-health` và `curl -fsS 'http://<host>/api/products?limit=1'`.
- [ ] Knowledge đã index: log deploy có `documents indexed`; nếu không, knowledge search rơi về lexical (kém hơn).
- [ ] Chat thật trên trình duyệt: hỏi "áo thun dưới 300k" thấy chữ chạy từng câu + thẻ sản phẩm; hỏi "thêm áo này vào giỏ" bị từ chối.
- [ ] Eval LLM thật (từ máy có truy cập stack, trỏ `EVAL_BASE_URL`): `npm --prefix chat-graph run eval`, mục tiêu >= 90%. Chạy `--group=guardrail` trước (không cần LLM). Lưu ý: eval ghi dữ liệu `eval_user_*` vào DB đang chạy, xem mục 6; nên chạy trên staging/`shop_test` nếu có.
- Ghi chú eval: nhóm `ticket` không ổn định với deepseek-v4-flash (model đôi khi hỏi xác nhận thay vì soạn ticket). Ngoài `ticket`, 51/51 case đạt trong 2 lần chạy ngày 2026-11-04. Không sửa expectation để "xanh"; điều tra code trước (`.claude/rules/eval.md`).
  - [ ] Ghi nhận kết quả eval (tỉ lệ tổng và theo nhóm) vào bảng theo dõi.

## 5. Kiểm tra vận hành

- [ ] **Reboot test** (sau khi PR "keep polling php-fpm readiness" đã deploy): reboot host, đợi vài phút, `docker compose ps` phải thấy `app` healthy mà không cần restart tay; sau đó `/nginx-health` và `/api/products?limit=1` = 200.
- [ ] Health: `docker compose exec -T chat-graph wget -qO- http://127.0.0.1:8090/health` có `"ok":true`; `docker compose exec -T rag-ml python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8000/health', timeout=5).read())"`.
- [ ] Chat-graph không lộ ra ngoài: `curl -s -o /dev/null -w '%{http_code}' http://<host>:8090/health` từ ngoài phải không kết nối được.
- [ ] Log cần theo dõi sau go-live (24-48 giờ đầu): `docker compose logs chat-graph --since 1h | grep -c 'turn failed'`. Nhà cung cấp LLM (kiraai.vn) không ổn định ngày 2026-11-04: nhiều "Request timed out" / 503.
- [ ] Busy rate: nếu tỉ lệ câu trả lời "đang bận" (`response_type=busy`) còn cao, tăng `LLM_TIMEOUT` (secret) hoặc đổi model/provider, rồi deploy lại.
- [ ] Theo dõi `docker compose logs app --tail=100` (lỗi php-fpm) và `docker compose logs nginx --tail=100` (429 rate limit chat).
- [ ] Đĩa trống còn >= 6000 MB (`df -h /`); volume `db_data`, `qdrant_data`, `hf_cache` còn nguyên.
- [ ] Sao lưu DB trước go-live và biết cách khôi phục (ghi lệnh/lịch backup vào bảng theo dõi).
- [ ] Nếu bật Langfuse: `curl -fsS http://localhost:3000/ >/dev/null`, thấy trace chat-graph (cần `APP_ENV=production`).

## 6. Dọn dữ liệu và tài khoản

- [ ] Nếu từng chạy eval trên production: xoá user `eval_user_*` cùng đơn hàng, ticket (`support_tickets`) và chat session của họ. Dịch vụ không có đường xoá nên làm qua SQL có sao lưu trước, chỉ với `username LIKE 'eval_user_%'`. Kiểm lại: không còn dòng nào khớp.
- [ ] Đổi mật khẩu tài khoản admin mặc định (`ADMIN_PASSWORD` và mọi admin tạo sẵn trong `database/`/seed); xác nhận mật khẩu cũ không còn đăng nhập được.
- [ ] Xoá hoặc vô hiệu hoá tài khoản test/demo khác; không còn đơn hàng và giỏ hàng thử.
- [ ] Nếu catalog đổi: sản phẩm 69, 83, 91, 94 (hết hàng) và 73 (còn hàng) chỉ là giả định của eval, không phải yêu cầu production.
- [ ] Rà soát lần cuối: không còn token mặc định `local-dev-...`, không còn `local-only-change-me`.

## 7. Bảng theo dõi

| # | Hạng mục | Người phụ trách | Trạng thái | Ngày |
| --- | --- | --- | --- | --- |
| 1 | Sửa SSH deploy (host up, cổng 22 mở cho runner, secrets host/port/key đúng, re-run workflow trên master) | | chưa làm | |
| 2 | Xác nhận production chạy bản mới (streaming, DSML fix, order-line grounding) | | chưa làm | |
| 3 | `.env`: `LLM_MODEL=deepseek-v4-flash-sale`, `APP_ENV=production`, `CHAT_GRAPH_TOKEN` và `MCP_SERVICE_TOKEN` dài ngẫu nhiên | | chưa làm | |
| 4 | Thay mọi giá trị Langfuse `local-only-change-me` (nếu dùng observability) | | chưa làm | |
| 5 | Merge và deploy PR "fix(app): keep polling php-fpm readiness" | | chưa làm | |
| 6 | Reboot test production: `docker compose ps` app healthy không cần restart tay | | chưa làm | |
| 7 | Theo dõi log `turn failed` sau go-live; cân nhắc tăng `LLM_TIMEOUT` hoặc đổi model nếu busy rate cao (kiraai.vn không ổn định 2026-11-04) | | chưa làm | |
| 8 | Eval LLM thật >= 90%; ghi nhận nhóm `ticket` không ổn định (ngoài `ticket` 51/51 hai lần ngày 2026-11-04) | | chưa làm | |
| 9 | `scripts/dev/gate.sh` xanh và CI xanh trên master | | chưa làm | |
| 10 | Smoke sau deploy `scripts/dev/smoke.sh` xanh | | chưa làm | |
| 11 | Dọn user `eval_user_*` và đơn/ticket/chat session của họ trên production | | chưa làm | |
| 12 | Đổi mật khẩu admin mặc định | | chưa làm | |
| 13 | Sao lưu DB và kiểm tra khôi phục | | chưa làm | |
