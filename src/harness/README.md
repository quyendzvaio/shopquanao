# Harness: what is left after the chat-graph migration

The chat agent (LLM, tool choice, policies) runs in the TypeScript `chat-graph/` service. PHP keeps the data side,
and `src/harness/` has the two components that still serve it:

| Component | Contains | Public entry | May use (besides service/model/core) |
|---|---|---|---|
| `tools/` | `ToolDefinitionCatalog`, `ToolApplicationService` | `ToolDefinitionCatalog::chatbotDefinitions()`, `ToolApplicationService::execute()` | none |
| `memory/` | `ChatbotConversationStore`, `PdoChatbotConversationStore`, `ChatbotSessionContext` | `ChatbotConversationStore`, `ChatbotSessionContext::resolve()` | repository |

`tests/unit/ArchitectureTest.php` enforces this table. Fix the code to match it, not the table to match the code.
The harness never uses `app/` or `agent/`, never reads env (`getenv`, `$_ENV`, `$_SERVER`), and no harness function
may exceed 80 lines.

## Who calls what

- `tools/`: `POST /api/internal/mcp` (`src/app/api/controllers/Internal/mcp.php`) calls `AgentFactory::executeTool`, which runs
  a tool listed in `ToolDefinitionCatalog` through `ToolApplicationService`. Cart, checkout and order-list tools do not exist.
  Keep `ToolDefinitionCatalog` in sync with `mcp-server/src/tools.ts` and `chat-graph/src/policies/allowList.ts`.
- `memory/`: `ChatbotService` (`src/agent/`) resolves the session with `ChatbotSessionContext` and saves each turn with
  `PdoChatbotConversationStore`. The rule-based memory itself (`ChatbotMemory`) lives in `src/service/chat/`.

See the per-component READMEs for details. For the chat pipeline itself see `.claude/rules/architecture.md` and
`docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md`.
