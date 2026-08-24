# Final agent evaluation — 2026-08-25

## Full 70-question suite

The release report combines 50 end-to-end HTTP turns for existing chatbot use cases with 10 UC1 explicit-styling and 10 UC2 proactive-styling cases.

```text
AGENT_EVAL_CASES=70
AGENT_EVAL_PASSED=70
AGENT_EVAL_FAILED=0
AGENT_EVAL_STATUS=PASS
```

Coverage: policy/RAG, product evidence, mixed multi-tool, order/auth, guardrail/non-RAG, UC1 explicit styling, and UC2 proactive styling.

The wider styling regression suite also passed 70/70: 20 explicit, 20 proactive, 15 suppression and 15 unrelated controls. All 20 proactive cases recorded two received user turns, zero provider calls before turn two and exactly one call after turn two.

```text
STYLING_STAGE_FAILURES=0
HALLUCINATED_PRODUCT_COUNT=0
PROVIDER_ID_LEAKAGE_COUNT=0
```

## Latency

```text
HTTP_50_AVG_MS=8765.06
HTTP_50_P50_MS=4947
HTTP_50_P95_MS=41494
HTTP_50_MAX_MS=53139

UC1_10_AVG_MS=7930.44
UC1_10_P95_MS=19627.65
UC2_10_AVG_MS=7296.93
UC2_10_P95_MS=14082.84
STYLING_CORE_20_AVG_MS=7010.25
STYLING_CORE_20_P95_MS=14069
```

HTTP and direct styling boundaries are intentionally not averaged together. The dominant styling stage was parallel Product Search (`5910.20 ms` average on the selected 20 cases).

Artifacts: `reports/eval/full_agent_eval_70_latest.json`, `reports/eval/chatbot_http_50_latest.json`, and `reports/eval/findmine_agent_eval_latest.json`.
