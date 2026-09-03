# Stylitics styling-reference agent evaluation — 2026-08-26

The reproducible default run is the balanced 50-case corpus selected from the
70-case source corpus (`--cases=50`). It exercises 15 explicit UC1 cases, 15
proactive UC2 cases, 10 suppression cases and 10 unrelated cases.

## Result

| Metric | Result |
| --- | ---: |
| Status | `PASS` |
| Cases | `50` |
| Passed / failed | `50 / 0` |
| Real provider calls | `30` (15 explicit + 15 proactive) |
| Stage failures | `0` in styling reference, extraction, normalization, Product Search, response, event state and grounding |
| Hallucinated product count | `0` |
| Provider-ID leakage count | `0` |
| RAGAS-eligible cases | `30` |

The provider boundary is the configured `stylitics_demo` reference path. It then
runs strict extraction, taxonomy normalization and parallel private Product
Search. Stylitics live mode remains blocked until vendor endpoint, authentication
and tool schema are supplied.

## Latency (milliseconds)

| Boundary / stage | Count | Avg | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: | ---: |
| All 50 cases | 50 | 5816.26 | 7101.04 | 14318.11 | 14920.06 |
| Explicit UC1 | 15 | 9747.18 | 7964.45 | 14920.06 | 14920.06 |
| Proactive UC2 | 15 | 9640.29 | 10176.62 | 14318.11 | 14318.11 |
| Suppression | 10 | 0.05 | 0.04 | 0.10 | 0.10 |
| Unrelated | 10 | 0.06 | 0.04 | 0.11 | 0.11 |
| Stylitics reference stage | 30 | 321.43 | 312 | 383 | 430 |
| LLM extraction stage | 30 | 174.63 | 133 | 461 | 488 |
| Normalization stage | 30 | 7.97 | 7 | 14 | 14 |
| Parallel Product Search | 30 | 8683.00 | 8889 | 13126 | 13699 |
| Total recommendation core | 30 | 9186.93 | 9620 | 13772 | 14341 |

Product Search is the measured bottleneck; suppression and unrelated intents
short-circuit before the styling provider.

## Reproduction

```bash
docker compose exec -T app php scripts/run_stylitics_agent_eval.php \
  --cases=50 --output=/tmp/findmine-agent-eval-50.json
```

The full machine-readable report used for the final run is
`/tmp/findmine-agent-eval-50-final.json` (also copied to
`reports/eval/findmine_agent_eval_50_final.json` when reports are retained).
