# Stylitics styling-reference agent evaluation

> Historical: 2026-08-26 on LangGraph agent orchestrator (50/50 PASS).
> Current: `deterministic_hybrid_pipeline` with the same balanced 50-case corpus
> (15 UC1 explicit, 15 UC2 proactive, 10 suppression, 10 unrelated). The provider
> boundary remains `stylitics_demo` reference path with parallel private Product Search;
> live mode stays blocked until vendor endpoint/auth/schema is supplied.
> Re-run `php scripts/run_stylitics_agent_eval.php --cases=50` on the current
> pipeline to reproduce; the numbers below are the historical run.

## Result (historical — LangGraph)

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

Deterministic pipeline (2026-09-07): also **50/50 PASS** (validator: deterministic).

## Latency — historical (LangGraph, milliseconds)

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

Deterministic pipeline (50 cases, grounded templates v2): avg **1415ms**, p95 **2543ms**.

## Reproduction

```bash
docker compose exec -T app php scripts/run_stylitics_agent_eval.php \
  --cases=50 --output=/tmp/findmine-agent-eval-50.json
```

The full machine-readable report used for the historical run was
`/tmp/findmine-agent-eval-50-final.json` (also copied to
`reports/eval/findmine_agent_eval_50_final.json` when reports were retained).
Current runs write to `reports/eval/stylitics_agent_eval_50.json`.
