# Use Case 1 — explicit styling

For a request such as “Áo này phối với gì?”, the chatbot resolves the shop anchor and calls `suggest_complementary_products`. The tool executes the shared demo MCP → raw suggestion → strict extraction → deterministic normalization → bounded parallel Product Search pipeline.

Only Product Search results become cards. If every normalized requirement has zero shop matches, the response is explicit: `Mình chưa tìm thấy sản phẩm phối hợp phù hợp trong shop lúc này.` Provider sample IDs and LLM output can never become cards.
