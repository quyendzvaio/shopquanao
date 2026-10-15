# Agent evaluation

Deterministic, in-process eval of the chatbot (no LLM, internal tool transport): `eval/cases.jsonl`, 40 cases run by `eval/AgentEvalCaseRunner.php` through `scripts/run_agent_eval.php`.

| Group | Cases | Covers |
| --- | --- | --- |
| `outfit` | 15 | UC-A `build_outfit`: budget, occasion/style tags, size, anchor product, out-of-stock anchor |
| `freeship` | 10 | UC-C `freeship_gap` and the proactive nudge (cart state, threshold, once per fingerprint) |
| `guardrail` | 8 | checkout/payment/cart actions refused, no tools used |
| `unrelated` | 7 | off-topic messages suppressed, no tools used |

## Case schema

One JSON object per line: `id`, `group`, `user` (true = create a logged-in user), optional `cart` (`[{product_id, quantity, size}]`), and `turns` (`[{message, expect}]`). `expect` keys (all optional): `intent`, `response_type`, `tool`, `no_tools`, `products` (exact card count), `min_products`, `max_total` (every outfit total), `first_set` (product ids of set 1), `all_sets_contain`, `size`, `freeship` (status), `first_product`, `reaches_threshold`, `nudge` (bool), `message_contains`. Every case also fails on out-of-stock cards for `build_outfit`/`freeship_gap`.

## Run

Needs the `shop_test` database (`scripts/dev/test-db.sh`) with migrations applied:

```bash
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS=... \
  scripts/dev/php scripts/run_agent_eval.php [--cases=eval/cases.jsonl] [--output=reports/eval/agent_eval.json]
```

Exit code 0 only when every case passes. CI runs it after the integration tests.

## Reading `reports/eval/agent_eval.json`

`passed` / `failed` / `by_group` (passes per group) and `cases[]`: `id`, `group`, `failures[]` (empty = pass) and per-turn `intent`, `response_type`, `tools`, `product_ids`, `outfits`, `freeship_status`, `freeship_nudge`, `answer`. Reports go to `reports/eval/` — gitignored, never commit.

## Principle

When a case fails, suspect the code first. Change an expectation only when the spec says the expectation is wrong, never to make the run green.
