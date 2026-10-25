# Harness: the agent minus the LLM

The agent is **harness + LLM**. The harness feeds the model the right context at each step and keeps every
decision (intent, tool, evidence, product cards) in PHP. The LLM is one pluggable part behind
`llm/LLMProvider`; `src/agent/AgentFactory` is the only place that reads chatbot env/config and picks
concrete classes. `src/agent/ChatbotService` is a thin facade over `TurnRunner`.

## One turn (`TurnRunner::run(TurnContext): TurnResult`)

```
loadMemory -> beforeTurn -> intent -> loop(planning / tools / evidence) -> response (llm via middleware) -> afterTurn -> persist
```

1. `loadMemory`: remember the user message, read the memory context.
2. `beforeTurn`: middleware may block the turn (the checkout guardrail does) and skip the middle steps.
3. `intent`: `IntentResolver` (deterministic first, LLM only enriches), or a clarification answer.
4. `loop`: `EvidenceExecutionLoop` plans tools, validates, runs them and checks the evidence.
5. `response`: `ResponseGenerator` builds the grounded draft. Only a streamed turn outside template mode asks
   the LLM (`StreamingResponseGenerator`) to rewrite it; `respond()` (non-streamed) keeps the draft as is, and
   template mode (`CHATBOT_TEMPLATE_RESPONSE=1`) streams the draft unchanged. Every model call goes through the
   middleware stack.
6. `afterTurn`: middleware may add to the result (free-shipping nudge, trace outbox).
7. `persist`: save messages, refresh the memory summary.

A blocked turn still goes through the response step, `afterTurn` and `persist`; it only skips 3-4.

## Components (spec 2026-10-22 section 4.1)

`tests/unit/ArchitectureTest.php` enforces this table. Fix the code to match it, not the table to match the code.
Every component may also use `service/`, `model/` and `core/`; `repository/` only where the table says so (the harness otherwise never reaches the database layer directly).

| Component | Contains | Public entry | May use (besides service/model/core) |
|---|---|---|---|
| `llm/` | `LLMProvider`, `StreamingLLMProvider`, `LLMResponse`, `ToolCall`, `LLMTransportException`, `ModelCall`, `MiddlewareModel` | interfaces + DTOs | middleware (only `Middleware`, `MiddlewareStack`) |
| `turn/` | `TurnRunner`, `TurnContext`, `TurnResult` | `TurnRunner::run()` | all components |
| `intent/` | `IntentResolver`, `DeterministicIntentParser`, `ConflictDetector`, `ConflictResolver`, `MergeEngine`, `LlmInputParser`, `SemanticEntityEnricher`, `TurnTierGate`, `CheckoutDetector` | `IntentResolver::resolve()`, `CheckoutDetector` | llm, planning |
| `planning/` | `ChatbotToolManifest`, `CapabilityRegistry`, `ToolPlanner`, `PlanValidator` | those four | none |
| `loop/` | `EvidenceExecutionLoop`, `ParallelToolExecutor`, `NoProgressDetector`, `EvidenceDecisionRouter` | `EvidenceExecutionLoop::run()` | planning, tools, evidence |
| `evidence/` | `EvidenceNormalizer`, `ProductConstraintVerifier`, `ObservationEvaluator`, `LightweightEvidenceScorer` | the first three | none |
| `tools/` | `ChatbotToolGateway`, `ToolDefinitionCatalog`, `ToolRegistry`, `McpToolGateway`, `ShadowToolGateway`, `ToolApplicationService` | `ChatbotToolGateway` | none |
| `response/` | `ResponseGenerator`, `StreamingResponseGenerator` | those two | llm |
| `memory/` | `ChatbotMemoryStore`, `ChatbotConversationStore`, `ChatbotMemory`, `PdoChatbotConversationStore`, `ChatbotSessionContext` | the two store interfaces | repository |
| `middleware/` | `Middleware`, `BaseMiddleware`, `MiddlewareStack`, `CheckoutGuardrail`, `AnswerTemplateFallback`, `FreeShippingNudge`, `TraceOutbox` | `Middleware`, `MiddlewareStack` | turn (only `TurnContext`, `TurnResult`), llm, intent (only `CheckoutDetector`), response, evidence, repository (only `ChatMemoryRepository`) |

The harness never uses `app/`, `agent/` or `llm_providers/`, and never reads env (`getenv`, `$_ENV`, `$_SERVER`).
No harness function may exceed 80 lines.

## Middleware

Hooks (`Middleware`): `beforeTurn(TurnContext): ?TurnResult` (return a result to block the turn),
`wrapModelCall(ModelCall, callable $next): LLMResponse`, `afterTurn(TurnContext, TurnResult): TurnResult`.
`BaseMiddleware` passes every hook through. Order and switches live in `config/harness.php`:
`beforeTurn` stops at the first blocker, `wrapModelCall` nests like an onion (first listed = outermost),
`afterTurn` runs top to bottom. An optional middleware's `afterTurn` failure is logged as
`{operation: middleware_<name>, success: false}` and skipped; a required one propagates.

| Middleware | Hook | Does | Required |
|---|---|---|---|
| `CheckoutGuardrail` | `beforeTurn` | `CheckoutDetector` match -> `unsupported_checkout` refusal, no tools. The pipeline's own `unsupported_checkout` branches stay as a second line of defence | yes |
| `AnswerTemplateFallback` | `wrapModelCall` (`purpose=answer`, streaming) | on LLM failure or empty answer, emit the grounded draft (appended after any partial deltas); sets `LLMResponse::$fallbackReason`, so `latency.streaming = 'template_fallback'` | yes |
| `FreeShippingNudge` | `afterTurn` | UC-C proactive line, once per cart fingerprint, shopping intents only | no |
| `TraceOutbox` | `afterTurn` (last) | enqueue the sanitized Langfuse trace | no |

When a class in `AgentFactory::REQUIRED_MIDDLEWARE` is missing from the config, `AgentFactory` throws a
`LogicException` while it builds the middleware stack (and so the turn runner) for a turn.

### How to add a middleware

1. Create `src/harness/middleware/MyThing.php`: `final class MyThing extends BaseMiddleware`, override only the hooks
   you use. Touch only `TurnContext`/`TurnResult`, llm, `CheckoutDetector`, response and evidence classes.
2. Add an arm to `AgentFactory::makeMiddleware()` that constructs it (this is where its dependencies come from).
3. List it in `config/harness.php` at the position you want it to run.
4. A guardrail that must never be switched off also sets `public const REQUIRED = true;` and goes into
   `AgentFactory::REQUIRED_MIDDLEWARE`.
5. Run `scripts/dev/composer dump-autoload`, add a unit test, then `ArchitectureTest`.

### How to add an intent or tool

1. Add a manifest entry in `config/chatbot_tools.php` (read through `planning/ChatbotToolManifest`).
2. Add the `ToolPlanner` builder and the `PlanValidator` rule.
3. Add scorer facts in `evidence/LightweightEvidenceScorer` and the `EvidenceNormalizer` / `ResponseGenerator` answer.
4. Declare the tool contract in `tools/ToolDefinitionCatalog` and `mcp-server/src/tools.ts`; keep both in sync
   (`mcp-server/tests/contract.test.ts` checks the list).
5. Add a unit test; pipeline behaviour changes need one, the eval alone is not enough. Add eval cases if user-visible.

Business logic for the tool belongs in `src/service/`, not here; `tools/ToolApplicationService` only wires it. SQL belongs in `src/repository/`; see `src/service/README.md` and `src/repository/README.md`.
