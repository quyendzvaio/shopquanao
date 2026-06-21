# Data Model — Chatbot Agentic Extensions

## New Entity: `tool_executions`
Log mỗi lần LLM gọi tool (để debug + analytics)

```sql
CREATE TABLE tool_executions (
  id int(11) NOT NULL AUTO_INCREMENT,
  session_id int(11) NOT NULL,
  tool_name varchar(100) NOT NULL,
  arguments longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(arguments)),
  result longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(result)),
  duration_ms int(11) DEFAULT NULL,
  success tinyint(1) DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY session_id (session_id),
  KEY tool_name (tool_name),
  CONSTRAINT tool_executions_ibfk_1 FOREIGN KEY (session_id) REFERENCES chat_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

## New Entity: `llm_config`
Lưu cấu hình LLM (provider, model, api_key) — dùng env var, không lưu vào DB.

## Existing Entities Used
All existing entities (products, categories, orders, order_items, cart, reviews, faqs, size_guides, outfit_suggestions, chat_sessions, chat_messages) remain unchanged.

## Validation Rules
- Tool arguments: JSON hợp lệ, không chứa HTML
- Tool result: JSON, UTF-8, max 10KB per result
- Orchestrator loop: max 5 iterations (tránh infinite loop)
- LLM response: validate có cấu trúc tool_call đúng format
