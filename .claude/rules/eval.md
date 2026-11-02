# Chatbot evaluation

The old PHP eval (`eval/cases.jsonl`, `scripts/run_agent_eval.php`, 40 cases through the deterministic pipeline) is removed (spec section 9.2). The chatbot is now evaluated in two layers (spec section 9):

| Layer | Where | LLM | When |
| --- | --- | --- | --- |
| CI | `npm --prefix chat-graph test` (node tests under `chat-graph/tests`, incl. the eval scorer and case-file checks) | scripted model, no network | every push; part of `scripts/dev/gate.sh` and the CI Code Quality job |
| Manual | `chat-graph/eval` (`npm --prefix chat-graph run eval`) | the real LLM from `.env` | before a release / after prompt or policy changes; target >= 90% of cases; never in CI |

## Cases (`chat-graph/eval/cases.jsonl`, 51)

| Group | Cases | Covers |
| --- | --- | --- |
| `s1_regression` | 5 | the spec section 1 messages: "quần baggy còn hàng" (clarification offering #73, names the sold-out #69), "váy hai dây" (#83), "khăn choàng" (#94) sold out, "mũ" (the hat #91 is sold out, the in-stock hoodie #62 only has "Có Mũ" in its name: a normal answer names the sold-out hat from `out_of_stock_products` and shows the #62 card), and the outfit + size multi-intent |
| `outfit` | 10 | `build_outfit`: budget, occasion/style, size, anchor product, budget below the cheapest set |
| `freeship` | 6 | `suggest_freeship_items` (gap, qualified, empty cart, guest) and the proactive nudge (fires once, not on the next turn) |
| `guardrail` | 9 | 8 checkout/payment/cart requests refused (`refusal`, no tools, no cards, no nudge; no LLM needed) + G09, a phrasing the regex misses where the model must not claim an order or cart add |
| `unrelated` | 5 | off-topic messages: no tools, no cards |
| `multi_intent` | 4 | one message, several tools (size + sold-out, outfit + policy, size + search, freeship + detail) |
| `sold_out` | 3 | sold-out edge: by name, by id, and accepting the offered alternative on the next turn |
| `catalog` | 3 | `get_categories`, plain search with a price filter, search as a logged-in user |
| `knowledge` | 2 | standalone `retrieve_knowledge` (returns policy, shipping fee), no cards |
| `order` | 2 | `get_order_status` (fresh user has no orders; guest is asked to log in) |
| `memory` | 2 | two-turn sessions: size then outfit, search then a thanks turn with no tools |

Sold-out expectations rely on products 69, 83, 91 and 94 having stock 0 (true in both `shop_db` and `shop_test`); #73 must be in stock. Re-check these if the catalog is re-seeded.

## Case schema

One JSON object per line: `id`, `group`, `user` (true = register a fresh logged-in user and chat with its Bearer token), optional `cart` (`[{product_id, quantity, size}]`, needs `user`), and `turns` (`[{message, expect}]`; the session token is carried between turns). `expect` keys, all optional:

| Key | Passes when |
| --- | --- |
| `response_type` | the response type equals it (string) or is one of it (list) |
| `tools_include` | every listed tool is in `tools_used` |
| `no_tools` | `true`: `tools_used` is empty |
| `products_include` | every id is among the returned cards |
| `min_products` / `max_products` | the card count is within the bound |
| `offered_include` | every id is in `offered` (alternatives asked about after a sold-out hit) |
| `message_contains` / `message_not_contains` | every / no fragment appears in the answer (case-insensitive); use for product names, not LLM wording |
| `message_contains_any` | `string[][]`: each inner list is a set of alternatives, at least one must appear, every list must be satisfied (wording-robust, e.g. sold-out phrases) |
| `message_mentions_price` | the answer mentions this VND amount in any spelling (`85.000đ`, `85,000 đ`, `85k`, `85 nghìn`) |
| `nudge` | `freeship_nudge` equals it |

`parseCases` (`chat-graph/eval/cases.ts`) rejects unknown keys and duplicate ids; the scorer is `chat-graph/eval/scorer.ts`.

## Run

Needs the stack up (`docker compose up -d`, nginx, app and chat-graph healthy) and a reachable LLM. First check the LLM without printing the key: `docker compose exec -T chat-graph sh -c 'wget -qO- --timeout=8 "$LLM_BASE_URL/models" >/dev/null && echo ok'` (a 401 also proves reachability; a timeout means do not run the eval).

```bash
npm --prefix chat-graph run eval                         # all cases
npm --prefix chat-graph run eval -- --group=guardrail    # a group (works with no LLM)
npm --prefix chat-graph run eval -- --only=S01,O02 --output=reports/eval/x.json
npm --prefix chat-graph run eval -- --pace-ms=30000      # slow LLM tier
```

- `--pace-ms=N` sets the gap between requests to `max(2300, N)`: it can slow the run, never go faster than the nginx limit (30 r/m). A turn makes 2-5 model calls, so for a 10 requests/minute LLM tier use `--pace-ms=30000` (about 25 min for the whole file); otherwise the provider answers 429 and the turn comes back `busy`. A non-numeric value aborts with an error.

- It talks to `http://localhost:${NGINX_HTTP_PORT:-80}` (override with `EVAL_BASE_URL`), through nginx, so it paces turns (about 2.3 s) and retries on HTTP 429 with backoff; it never bypasses the rate limit.
- It scores against whatever database the running `app` uses (check `docker compose exec -T app printenv DB_NAME`; `shop_db` in the default stack, `shop_test` if you started the stack on it). It never repoints the stack.
- Users and carts are created only through PHP: `chat-graph/eval/fixtures.php` is piped into the app container (`docker compose exec -T app php /dev/stdin '<json>' < fixtures.php`; the image has `src/` but does not bind-mount the repo, and plain `php -` is not accepted) and uses `UserService` and `CartService`. Each case gets a fresh `eval_user_<runid>_<n>` user, because a Bearer user reuses its latest chat session. Carts are emptied at the end (`cleanup` refuses any id whose username is not `eval_user_*`); the users and their chat sessions stay (the services have no delete path), clearly named `eval_user_*`.
- Exit code 0 only when the pass rate is >= 0.9.

## Reading `reports/eval/chat_graph_eval.json`

`passed` / `failed` / `rate` / `by_group` (`{passed, total}` per group) and `cases[]`: `id`, `group`, `failures[]` (empty = pass) and per-turn `message`, `answer`, `response_type`, `product_ids`, `tools`, `offered_ids`, `nudge`. Reports go to `reports/eval/` — gitignored, never commit.

## Principle

When a case fails, suspect the code first. Change an expectation only when the spec says the expectation is wrong, never to make the run green. The CI layer must never depend on a real LLM.
