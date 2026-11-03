# memory

Conversation persistence for a chat session.

- Entry points: the `ChatbotConversationStore` interface (messages, routing metadata, tool executions) and
  `ChatbotSessionContext` (resolves the durable chat session from the session token or the bearer user).
- Implementation: `PdoChatbotConversationStore`.
- The rule-based memory (`ChatbotMemory`: summary, slots, long-term memory for logged-in users) moved to `src/service/chat/`.
- May use: `repository` (plus service/model/core).
