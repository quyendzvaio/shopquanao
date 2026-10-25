# memory

Conversation memory and persistence for a chat session.

- Entry points: the `ChatbotMemoryStore` interface (`ensureSchema`, `rememberUserMessage`, `refreshSummary`) and the
  `ChatbotConversationStore` interface (messages, routing metadata, tool executions).
- Implementations: `ChatbotMemory` (rule-based summary and slots, long-term memory for logged-in users),
  `PdoChatbotConversationStore`, `ChatbotSessionContext`.
- May use: nothing else in the harness (plus service/model/core).
