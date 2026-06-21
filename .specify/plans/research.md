# Research: Chatbot Agentic RAG

## 1. LLM Provider — Decision: Abstraction + Curl (No Guzzle)

**Decision:** Dùng interface `LLMProvider` với 3 implementations (OpenAI, Claude, Gemini). Dùng cURL thuần (không Guzzle) để giữ đồng bộ với style PHP thuần hiện tại.

**Rationale:**
- Project hiện tại không có Composer, không dùng Guzzle
- Thêm Composer chỉ cho HTTP client là overkill
- cURL đã có sẵn trong PHP 8.2, đủ cho use case gọi API LLM

**Provider support:**
| Provider | Auth Header | Tools Format | Tool Result |
|---|---|---|---|
| OpenAI | `Authorization: Bearer` | `tools[].function` | role: `tool` |
| Claude | `x-api-key` | `tools[].input_schema` | role: `user` + `tool_result` block |
| Gemini | URL query `?key=` | `tools[].function_declarations` | role: `function` + `functionResponse` |

## 2. Knowledge Base — Decision: Keyword Search + Tool Call (No Embedding)

**Decision:** Không dùng vector embedding. Dùng tool call để query DB/API.

**Rationale:**
- Database đã có faqs, size_guides, outfit_suggestions — query trực tiếp nhanh hơn
- Số lượng documents nhỏ (< 100), embedding không mang lại lợi ích đáng kể
- Tool call cho phép LLM quyết định khi nào cần tra cứu, khi nào trả lời từ kiến thức đã học
- Có thể nâng cấp lên embedding sau này khi KB lớn hơn

## 3. Streaming — Decision: Không streaming ở phase 1

**Decision:** Dùng fetch → chờ response → hiện text. Không SSE ở phase 1.

**Rationale:**
- Thêm độ phức tạp đáng kể (PHP SSE + JS EventSource)
- Use case chatbot shop không yêu cầu real-time
- Có thể thêm sau khi core đã ổn định
- Widget đã có typing indicator giả (3 dot animation) che latency

## 4. API Key Storage — Decision: env var + .env file

**Decision:** Mở rộng pattern `getenv()` hiện tại. Thêm `.env` loader thủ công.

**Rationale:**
- Project đã dùng `getenv('DB_HOST')` pattern — nhất quán
- Docker đã hỗ trợ env_file
- Thêm file `.env.example` làm template
- `.env` nằm trong `.gitignore`

## 5. Orchestration Loop — Decision: PHP synchronous loop

**Decision:** Vòng lặp đồng bộ, tối đa 5 iterations, trong PHP.

```
1. Gọi LLM với messages + tools
2. Parse response:
   - Nếu text → return
   - Nếu tool_call → execute tool → append result → quay lại 1
3. Hết max_turns → fallback text
```

## 6. Fallback Strategy

**Decision:** 3-tier fallback:
1. LLM available → dùng agentic orchestrator
2. LLM fail/not configured → dùng rule-based engine hiện tại
3. Rule-based cũng fail → response mặc định
