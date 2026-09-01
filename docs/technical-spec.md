# Đặc Tả Kỹ Thuật Fashion Shop

Phiên bản: 2.0
Cập nhật: 2026-08-01

## Tổng Quan

Fashion Shop là website bán quần áo bằng PHP 8.2 và MariaDB. Docker Compose chạy Nginx public reverse proxy, PHP app, MariaDB, Redis, Qdrant và hai sidecar FastAPI cho RAG/reranking.

| Service | Vai trò |
|---|---|
| `nginx` | Public entrypoint, reverse proxy, rate limit và WebSocket upgrade |
| `app` | PHP website, REST controllers và chatbot service |
| `chat-stream` | WebSocket delivery gateway; forwards one chat turn to PHP and streams only validated output |
| `db` | Product, user, cart, order, chat, memory và execution logs |
| `redis` | Cache; app có file fallback |
| `qdrant` | Vector store cho knowledge base |
| `rag-ml` | Embedding và knowledge retrieval/rerank |
| `reranker` | Product result reranking |

## HTTP Flow

```text
Browser
  -> Nginx :80
  -> PHP app (REST) / chat-stream (WebSocket)
  -> chat-stream -> PHP app for `/ws/chatbot`
      -> api/index.php
      -> api/controllers/*
      -> MariaDB / Redis / sidecars
  -> JSON hoặc PHP-rendered page
```

Các route chính gồm auth, products, cart, orders, chatbot, knowledge và admin. Bearer middleware xác thực user cho các thao tác cá nhân; chatbot guest vẫn có session token riêng.

## Chatbot

Chatbot dùng deterministic-first hybrid pipeline. `ChatbotService` là application boundary thật; `IntentResolver` và `ToolPlanner` PHP quyết định intent/tool. LLM chỉ có thể bổ sung structured entity JSON và không nhận tool definitions.

Chi tiết call graph, WebSocket event contract, intent-tool mapping, constraint
validation, memory và test nằm tại [chatbot-spec.md](chatbot-spec.md).

## Product Search

`ToolRegistry` tạo query PDO bằng allowlist cho category, price và stock. Size được kiểm từ `product_sizes`; màu và các thuộc tính text được chuẩn hóa/match từ `products.name + products.description`. Kết quả luôn đi qua `ProductConstraintVerifier` trước khi tạo response cards.

## Policy RAG

Policy queries được route tới `retrieve_knowledge`. Retriever ưu tiên Qdrant/RAG sidecar khi khả dụng và có database fallback. Câu trả lời được dựng từ evidence đã lấy, không dùng model để tự suy diễn chính sách shop.

## Persistence

| Bảng | Dữ liệu chính |
|---|---|
| `chat_sessions` | Session guest/user |
| `chat_messages` | User/bot message và pipeline metadata |
| `chat_session_memory` | Summary và slots |
| `user_long_term_memory` | Preference/fact theo user đăng nhập |
| `tool_executions` | Tool args/result/latency/success và routing diagnostics |

## Quality Gates

- PHP lint và PHPStan level 1.
- PHPUnit Unit và Integration suites.
- Python syntax check cho RAG/eval scripts.
- Secret scan và Trivy filesystem/image scan.
- RAGAS/Langfuse chạy offline khi có evaluator credentials; không tham gia production request.

Secrets chỉ được truyền qua environment và không được commit. Production path không dùng LLM-generated SQL.

## SOLID Review

| Nguyên tắc | Trạng thái | Bằng chứng và giới hạn |
|---|---|---|
| Single Responsibility | Đạt một phần | `ChatbotService` điều phối use case; SQL lưu hội thoại và telemetry đã chuyển sang `PdoChatbotConversationStore`. `KnowledgeRetriever` và `ToolRegistry` vẫn là các module lớn, cần tách theo adapter nếu tiếp tục mở rộng. |
| Open/Closed | Đạt một phần | Tool contract được cô lập qua `ChatbotToolGateway`; tuy nhiên thêm một intent/tool mới vẫn cần cập nhật `CapabilityRegistry`, `ToolPlanner` và validator tương ứng. |
| Liskov Substitution | Đạt | Các implementation được gọi qua contract không thay đổi precondition hoặc response shape của contract. |
| Interface Segregation | Đạt | `ChatbotToolGateway`, `ChatbotMemoryStore`, `ChatbotConversationStore` và `LLMProvider` chỉ công bố các thao tác consumer cần. |
| Dependency Inversion | Đạt ở application boundary | `ChatbotService` phụ thuộc vào interface cho tool, memory và persistence; production inject adapter PDO/ToolRegistry mặc định. Các component thuần như parser/generator vẫn được khởi tạo trực tiếp vì không có I/O dependency. |

Các thay đổi SOLID không thay đổi public API. Constructor mặc định vẫn tạo production adapter; test có thể inject fake implementation mà không cần database, Qdrant hoặc LLM.
