# LangGraph agent orchestration plan

## Decision

Move conversational orchestration to one LangGraph.js module and keep PHP as
the catalog, policy, order, cart, authentication, and authorization system of
record. The graph calls the existing MCP tools; it must not query shop tables
or invent product cards directly.

Do not layer LangGraph on top of `DeterministicIntentParser`, `ToolPlanner`, and
`EvidenceExecutionLoop`. During migration, a feature flag selects exactly one
orchestrator per request. After parity, remove the replaced PHP semantic router.

LangGraph.js is the preferred implementation because the repository already
ships a Node 22 MCP and streaming runtime. This avoids adding a second Python
application solely for orchestration.

## External interface

The graph module exposes one deep interface:

```ts
invokeAgentTurn({
  threadId,
  userId,
  message,
  authorization,
  proactiveEvent,
}): Promise<AgentTurnResult>
```

Callers do not select nodes or tools. They provide identity and one user turn;
the module owns state hydration, planning, tool execution, evidence checks,
proactive transitions, response generation, and checkpointing.

## State

Thread-scoped state:

- messages and compact conversation summary
- resolved entities with source and confidence
- active product and active category
- requested fields
- selected tool calls and bounded retry budget
- normalized evidence and private catalog cards
- pending UC2 anchor, remaining turns, eligibility, and last failure reason
- final answer, response type, and sanitized diagnostics

Cross-thread user memory:

- stable body measurements and usual size
- explicit style/color/material preferences
- explicit dislikes and opt-outs

Do not infer durable preferences from a single product search.

## Nodes and transitions

1. `load_context` loads the checkpoint and consumes any pending cart event.
2. `understand_turn` asks the model for a strict structured intent/entity
   object. It performs semantic interpretation without keyword or regex routing.
3. `authorize_plan` deterministically rejects unavailable or unauthorized tool
   calls and enforces confirmation for mutations.
4. `plan_tools` produces bounded MCP tool calls from the structured state.
5. `execute_tools` uses a `ToolNode` adapter around the existing shop MCP tools.
6. `normalize_evidence` strips provider identifiers and normalizes tool output.
7. `verify_evidence` applies numeric, identity, ownership, schema, and private
   catalog checks. These are structural safety checks, not semantic word matching.
8. `advance_proactive_state` advances UC2 from persisted state and records an
   explicit reason for `waiting`, `suppressed`, `retryable_failure`, or `shown`.
9. `generate_answer` answers only from verified evidence.
10. `persist_turn` checkpoints thread state and writes explicit long-term memory.

Conditional edges use structured enums and validation results. They must not
search the user's sentence for trigger words.

## Persistence

Use the existing numeric chat session ID as LangGraph `thread_id`. Production
must use a durable database checkpointer; an in-memory saver is test-only and
loses state on restart. Keep user memory in a durable store namespaced by user
ID. Checkpoints need a retention policy because they grow over time.

The current MariaDB conversation tables remain authoritative during migration.
Before production cutover, choose one of:

1. a dedicated Postgres checkpointer supported by LangGraph; or
2. a tested MariaDB checkpointer adapter owned by this repository.

Do not reuse the optional Langfuse Postgres database for agent state.

## UC2 reliability

The user-visible UC2 state is armed synchronously in the cart transaction. The
outbox and Redis stream remain useful for other consumers, but worker health is
not allowed to determine whether the recommendation exists.

The graph records these observable statuses:

- `not_armed`
- `waiting_for_turn`
- `waiting_for_suitable_context`
- `tool_retryable_failure`
- `no_private_catalog_match`
- `shown`

No exception may be converted to an unqualified `silent` result.

## Migration gates

1. Add contract tests for the graph interface using captured Vietnamese
   conversations, including the size and UC2 regressions.
2. Add LangGraph.js behind `CHATBOT_ORCHESTRATOR=langgraph`; keep PHP as the
   default until parity is demonstrated.
3. Run both orchestrators in shadow mode and compare structured plans, tools,
   evidence, latency, and answers without executing mutations twice.
4. Cut over read-only intents first, then size/policy, then UC1/UC2.
5. Remove lexical semantic routing only after the graph passes the evaluation
   corpus and live canary thresholds.
6. Delete the replaced PHP router/planner tests; retain tests at the graph's
   external interface and deterministic safety validators.

## Acceptance criteria

- No keyword/regex matching selects an intent or tool.
- Follow-up turns preserve product, category, measurements, and requested size.
- UC1 resolves a concrete catalog anchor before calling styling.
- UC2 is shown after the configured turn transition even if Redis workers are
  stopped, and every suppression has a recorded reason.
- Only authenticated, authorized, allow-listed tools execute.
- Every product in an answer is a verified private catalog card.
- Graph checkpoints survive process restarts and remain isolated by thread ID.

## References

- https://docs.langchain.com/oss/javascript/langgraph/persistence
- https://docs.langchain.com/oss/javascript/langgraph/add-memory
- https://docs.langchain.com/oss/javascript/langgraph/quickstart
