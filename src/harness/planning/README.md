# planning

Decides which tools to call for an intent, from a manifest, and validates the plan.

- Entry points: `ChatbotToolManifest` (reads `config/chatbot_tools.php`), `CapabilityRegistry` (tools from the gateway
  definitions, never hardcoded), `ToolPlanner`, `PlanValidator`.
- Adding an intent/tool = a manifest entry plus a planner builder and a validator rule (see `../README.md`).
- May use: nothing else in the harness (plus service/model/core).
