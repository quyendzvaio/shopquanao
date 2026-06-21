# Quickstart — Chatbot Agentic RAG Validation

## Prerequisites
- Docker containers running (`docker compose ps`)
- DB initialized with new tables (faqs, size_guides, outfit_suggestions, chat_sessions, chat_messages)
- LLM API key configured (hoặc fallback rule-based)

## Test Scenarios

### 1. API Endpoints mới
```bash
# Size guide
curl "http://localhost:8090/api/size-guide?height=170&weight=65&category_id=1"
# → recommended: M

# FAQ
curl "http://localhost:8090/api/faq?category=shipping"
# → danh sách FAQ về vận chuyển

# Outfit
curl "http://localhost:8090/api/outfit?search=áo%20thun"
# → gợi ý phối đồ với áo thun
```

### 2. Chatbot (LLM mode)
```bash
curl -X POST http://localhost:8090/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"tìm áo khoác dưới 500k"}'
# → LLM gọi tool search_products → tổng hợp response
```

### 3. Chatbot (Fallback rule-based)
```bash
# Khi LLM_API_KEY không được cấu hình:
curl -X POST http://localhost:8090/api/chatbot \
  -H 'Content-Type: application/json' \
  -d '{"message":"chào bạn"}'
# → Rule-based engine trả lời
```

### 4. Chat with context (có đăng nhập)
```bash
# Login lấy token
TOKEN=$(curl -s -X POST http://localhost:8090/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"hiep@gmail.com","password":"hiep"}' | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")

# Hỏi về đơn hàng (có token)
curl -X POST http://localhost:8090/api/chatbot \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"message":"đơn hàng của tôi"}'
```

### 5. Frontend widget
- Mở `http://localhost:8090/`
- Click nút chat dưới phải
- Gõ "giúp" → thấy danh sách tính năng
- Gõ "tìm áo khoác" → kết quả
- Gõ "chọn size cho 1m7 65kg" → tư vấn size

## Expected Behavior

| Scenario | LLM Mode | Rule-based Fallback |
|---|---|---|
| "tìm áo khoác dưới 500k" | LLM chọn tool → API → tổng hợp | Keyword match → DB query → text |
| "cao 1m7 nặng 65kg mặc size gì" | LLM chọn suggest_size | Keyword match size_advice |
| "đơn hàng của tôi" | LLM chọn get_my_orders (cần auth) | Keyword match + check session |
| Câu hỏi không khớp tool nào | LLM tự trả lời từ kiến thức | "Tôi chưa hiểu" + gợi ý |
