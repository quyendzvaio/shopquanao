# llm

The model port. Defines what the harness needs from an LLM and nothing about any vendor.

- Entry points: `LLMProvider` (chat) and `StreamingLLMProvider` (token stream) interfaces; DTOs `LLMResponse`
  (with `?fallbackReason`), `ToolCall`, `LLMTransportException`.
- `ModelCall` describes one model call (purpose, messages, options, grounded draft, `onDelta`, stream).
  `MiddlewareModel` decorates a real provider and routes every call through `MiddlewareStack::wrapModelCall`.
  Callers pass `$options["harness"]` (purpose, grounded_draft); `MiddlewareModel` strips it before the provider.
- May use: `middleware/` (only `Middleware`, `MiddlewareStack`), plus service/model/core.
- Concrete providers live in `src/llm_providers/` (outside the harness) and are chosen in `src/agent/AgentFactory`.
