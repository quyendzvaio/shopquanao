# Docker — Hướng dẫn vận hành

> **Services**: `app` (PHP 8.2) + `db` (MariaDB 10.11) + `reranker` (Python 3.12)  
> **Ports**: 8090 (web) · 3308 (DB) · 8001 (reranker) · 8091 (phpMyAdmin)

---

## Yêu cầu hệ thống

- Docker Engine 20.10+
- Docker Compose v2
- RAM tối thiểu: **2 GB** (app ~100 MB + reranker ~785 MB + DB ~200 MB)

---

## Khởi động lần đầu

```bash
docker compose up -d --build
```

Quá trình tự động:
1. Build PHP 8.2 + Apache (stage 1: composer install, stage 2: production)
2. Build reranker sidecar (Python + PyTorch CPU + cross-encoder model)
3. Pull MariaDB 10.11 + import dữ liệu từ `sql/shop_db.sql`
4. Service discovery qua Docker network (app → db, app → reranker)

> **Lưu ý**: Lần build đầu reranker phải download model (BAAI/bge-reranker-v2-m3, ~1.1GB).  
> Build có thể mất 20-30 phút tùy bandwidth.

---

## Truy cập

| Service | URL | Ghi chú |
|---|---|---|
| Website | http://localhost:8090 | Fashion Shop |
| Reranker | http://localhost:8001/health | Health check |
| phpMyAdmin | http://localhost:8091 | Profile: tools |

phpMyAdmin không chạy mặc định. Khi cần:

```bash
docker compose --profile tools up -d
```

## Tài khoản

### Admin website

```
admin / 123456
admin@gmail.com / 123456
```

### phpMyAdmin

```
Server:   db
User:     root
Password: root_pass
```

### Database (app)

```
Database: shop_db
User:     shop_user
Password: shop_pass
```

---

## Services

```yaml
services:
  app:          # PHP 8.2 + Apache
    build:      .
    ports:      8090:80
    env:        LLM_API_KEY, RERANKER_URL, DB_HOST...
    depends_on: db (healthy), reranker (started)

  db:           # MariaDB 10.11
    image:      mariadb:10.11
    ports:      3308:3306
    volumes:    db_data, sql/shop_db.sql, mariadb-ft.cnf (FULLTEXT config)
    healthcheck: 30 retries, 1s interval

  reranker:     # Python 3.12 + FastAPI + PyTorch (CPU)
    build:      ./docker/reranker
    ports:      8001:8000
    healthcheck: Python urllib, start_period: 120s (model warmup)

  phpmyadmin:   # Management UI (profile: tools)
    image:      phpmyadmin:5.2
    ports:      8091:80
```

---

## Các lệnh thường dùng

```bash
# Build & start
docker compose up -d --build

# Build riêng từng service (nhanh hơn)
docker compose build reranker
docker compose build app
docker compose up -d

# Logs
docker compose logs -f app
docker compose logs -f reranker

# Restart
docker compose restart app
docker compose restart reranker

# Xóa cache search (cần sau khi sửa ToolRegistry)
docker exec shop_quan_ao_app sh -c 'rm -rf /tmp/shop_cache/*'

# Dừng
docker compose down

# Dừng + xóa volume DB (reset toàn bộ)
docker compose down -v
docker compose up -d --build
```

---

## Kiểm tra hoạt động

```bash
# Web
curl -s http://localhost:8090 | head -5

# API products
curl -s "http://localhost:8090/api/products?search=áo&limit=3"

# Chatbot
curl -s -X POST http://localhost:8090/api/chatbot \
  -H "Content-Type: application/json" \
  -d '{"message": "áo khoác dưới 500k"}' | python3 -m json.tool

# Reranker health
curl -s http://localhost:8001/health

# Reranker test
curl -s -X POST http://localhost:8001/rerank \
  -H "Content-Type: application/json" \
  -d '{"query":"áo","texts":["Áo thun","Quần jeans"]}'

# Kết nối DB
docker exec -it shop_quan_ao_db mysql -uroot -proot_pass shop_db \
  -e "SELECT id, name, price FROM products LIMIT 5"

# PHP syntax check
docker exec shop_quan_ao_app php -l /var/www/html/api/controllers/chatbot/ToolRegistry.php
```

---

## Monitoring

```bash
# Docker stats
docker stats shop_quan_ao_app shop_quan_ao_reranker shop_quan_ao_db

# Logs realtime
docker compose logs -f --tail=20

# Reranker latency
docker exec shop_quan_ao_app sh -c \
  'cat /var/log/apache2/error.log | grep "Reranker latency" | tail -5'

# Cache usage
docker exec shop_quan_ao_app sh -c \
  'du -sh /tmp/shop_cache/ 2>/dev/null; find /tmp/shop_cache/ -type f | wc -l'

# Tool execution stats
docker exec shop_quan_ao_app sh -c \
  'curl -s http://localhost:8090/api/chatbot/...'  # TODO: admin dashboard
```

---

## Xử lý sự cố

| Vấn đề | Nguyên nhân | Fix |
|---|---|---|
| Reranker unhealthy | Model chưa download xong | `docker logs shop_quan_ao_reranker -f`, đợi warmup (~4 phút) |
| Composer install fail | Thiếu `api/cache/` trong build | Build lại: `docker compose build --no-cache app` |
| Cache cũ (rerank không chạy) | File PHP cũ trong container | `docker compose build app && docker compose up -d` |
| Reranker timeout | Quá nhiều items | Kiểm tra `RERANK_MAX_ITEMS` trong `ToolRegistry.php` |

---

## Kiến trúc Network

```
Container: shop_quan_ao_app
  ├── localhost:80      ← PHP-to-PHP (internal API calls)
  ├── db:3306           ← PDO connection
  └── reranker:8000     ← HTTP (curl) rerank calls
      
Container: shop_quan_ao_reranker
  └── localhost:8000    ← FastAPI (uvicorn)
```

> `getInternalApiUrl()` returns `http://localhost` (Apache port 80, container internal).  
> `RERANKER_URL` env = `http://reranker:8000` (Docker DNS).
