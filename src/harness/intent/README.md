# intent

Turns a user message into a structured intent. Deterministic first; the LLM only enriches.

- Entry points: `IntentResolver::resolve()` and `CheckoutDetector::matches()` (the single source of the checkout regex,
  shared by the `CheckoutGuardrail` middleware and the intent parser).
- Pieces: `DeterministicIntentParser`, `ConflictDetector` / `ConflictResolver`, `MergeEngine`, `LlmInputParser`,
  `SemanticEntityEnricher`, `TurnTierGate` (L0 = no LLM call, L1 = fill unresolved spans), `PartialParseResult`.
- The LLM may only propose allow-listed fields for unresolved spans; it never picks the intent or a tool.
- May use: `llm/` (interface only), `planning/` (capability registry), plus service/model/core.
