# tools

The tool boundary between the harness and the shop services.

- Entry point: the `ChatbotToolGateway` interface. Implementations: `ToolRegistry` (in-process), `McpToolGateway`
  (private mcp-server stdio child, calls back to `/api/internal/mcp`), `ShadowToolGateway` (runs both and compares).
  The transport is chosen in `src/agent/AgentFactory` (`CHATBOT_TOOL_TRANSPORT`).
- All of them execute through `ToolApplicationService`, which wires tools to `src/service/*`.
- `ToolDefinitionCatalog` is the contract list; keep it in sync with `mcp-server/src/tools.ts`. Cart/checkout tools do not exist.
- May use: nothing else in the harness (plus service/model/core).
