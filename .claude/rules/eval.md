# Chatbot evaluation

The old PHP eval (`eval/cases.jsonl`, `scripts/run_agent_eval.php`, 40 cases through the deterministic pipeline) is removed (spec section 9.2). The chatbot is now evaluated in two layers (spec section 9):

| Layer | Where | LLM | When |
| --- | --- | --- | --- |
| CI | `npm --prefix chat-graph test` (node tests under `chat-graph/tests`) | scripted model, no network | every push; part of `scripts/dev/gate.sh` and the CI Code Quality job |
| Manual | `chat-graph/eval` | the real LLM from `.env` | before a release / after prompt or policy changes; target >= 90% of cases |

Task 9 fills in this file: the case table, the case schema, the run command and how to read the report. Until then the authoritative description is spec section 9.

## Principle

When a case fails, suspect the code first. Change an expectation only when the spec says the expectation is wrong, never to make the run green. The CI layer must never depend on a real LLM.
