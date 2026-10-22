# turn

Runs exactly one chat turn.

- Entry point: `TurnRunner::run(TurnContext): TurnResult`. The turn is streamed iff `TurnContext` has an `emit` callback.
- Order: loadMemory, beforeTurn, intent, loop, response, afterTurn, persist (see `../README.md`). Each step is a short private method.
- `TurnContext` (message, session, user, memory, emit) and `TurnResult` (typed array of message, answer, products,
  cards, response_type, primary_intent, latency, trace_id...) are plain data; middleware sees only these two types.
- May use: every other component (it is the orchestrator). Built by `src/agent/AgentFactory::turnRunner()`; callers use
  the `src/agent/ChatbotService` facade.
