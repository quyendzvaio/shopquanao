# tools

The tool boundary between the chat agent and the shop services.

- `ToolDefinitionCatalog` is the contract list (the allow-list the MCP bridge enforces); keep it in sync with
  `mcp-server/src/tools.ts` and `chat-graph/src/policies/allowList.ts`. Cart/checkout tools do not exist.
- `ToolApplicationService` wires each tool to `src/service/*`. It is reached through `AgentFactory::executeTool`
  (`src/agent/`), which `POST /api/internal/mcp` calls for every tool call chat-graph makes.
- May use: nothing else in the harness (plus service/model/core).
