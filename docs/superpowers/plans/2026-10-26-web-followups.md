# Web follow-ups after Part B: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the follow-ups that Part B left in spec §7 step 11:
- page bugs that were kept for byte identity;
- state-changing GET requests;
- swallowed DB errors;
- the cart size reset;
- env-admin shopping;
- the stream `chat.error` with no LLM;
- MCP tool filtering.

**Architecture:** Each task is a deliberate, reviewed behaviour change on top of the Part B layers (`public/` entry, then `src/app/web` handler, then `templates/pages`, with services and repositories underneath). The HTTP snapshot harness stays the oracle. Every task lists the snapshot diff it expects, proves the diff is limited to that list, and then re-baselines.

**Tech Stack:** PHP 8.2 (Composer classmap, no namespaces), PHPUnit, PHPStan level 1, MariaDB 10.11, `scripts/dev/web-snapshot.sh`.

**Spec:** `docs/superpowers/specs/2026-10-22-layered-refactor-design.md` §7 step 11 (the follow-up list). The decisions below come from the user on 2026-10-26.

## User decisions (2026-10-26)

- **U1. Cart size.** When a product already in the cart is added again *without* a size, keep that line's size and only add the quantity. A new line still defaults to `S`. This applies to web and API, both of which go through `CartService::add`.
- **U2. CSRF.** Every state-changing request from a signed-in user becomes a POST that carries a per-session CSRF token, and the handler checks it:
  - cart update (qty/size) and remove;
  - add to cart;
  - checkout;
  - submit review;
  - admin order status;
  - admin product add/edit/delete;
  - admin user lock/unlock/role.

  Login, register and logout are out of scope.
- **U3. Env admin shopping.** A signed-in admin with no real user row (`user_id` missing or `<= 0`) cannot add to cart or check out. The message is `Tài khoản quản trị không dùng để mua hàng.` A DB admin with a real `user_id` can still shop.

## Global Constraints

- **Gate after every task.** Every command must exit 0:
  - `bash scripts/dev/gate.sh` (created in Task 1);
  - unit tests;
  - phpstan level 1;
  - `test-db` + integration;
  - agent eval 40/40;
  - mcp-server tests;
  - `scripts/dev/web-snapshot.sh current && diff -r reports/web-snapshot/baseline reports/web-snapshot/current`.
- **Snapshot discipline.**
  - A task's snapshot diff must be limited to the scenarios its section lists.
  - Before re-baselining, write the per-scenario list (name, plus one line on what changed and why) into the commit body.
  - Then re-baseline with `rm -rf reports/web-snapshot/baseline && mv reports/web-snapshot/current reports/web-snapshot/baseline`.
  - New PHP warnings in `zz-server-log-*.txt` are bugs. Warnings that disappear because a task fixed them are expected.
- **Layer rules stay as enforced by `tests/unit/ArchitectureTest.php`.**
  - `app` uses agent, service, model and core, and never repository.
  - SQL lives only in repositories.
  - Env is read only in `agent/` and `core/`.
  - Request superglobals are never read in harness, service, repository or model.
- **Hard rule.** The chatbot never adds to cart, checks out or takes payment.
- **Tests.**
  - Existing assertions may change only where a task below says the pinned behaviour changes. Name each changed assertion in the commit body.
  - The 40 eval cases do not change.
- **Hygiene.**
  - Files are mostly CRLF. Keep each file's line endings.
  - Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
  - Never commit `.env`, `reports/`, `output/` or `docs/*.docx`.
  - Never run `docker compose down` or `--remove-orphans`.

## Review Focus

1. **CSRF bypass.** A POST with a missing or wrong token must change nothing, and that must hold on every listed action. A GET to an old URL must change nothing either. Tests in Task 3.
2. **The admin guard after unification.**
   - A guest or a customer hitting any admin page is redirected to `login.php` (the admin login) and nothing is written.
   - An env admin and a DB admin both get in.

   Tests in Task 2.
3. **Size merge.** Re-adding with no size keeps `M`. Re-adding with `L` sets `L`. A new line with no size gets `S`. The merged quantity is still capped at stock. Tests in Task 4.
4. **DB failure surfaces as 500, not as a domain message.**
   - A `PDOException` in the cart, order, checkout or order-details paths must not print SQL text to the user.
   - It must not turn into "not found" or "out of stock".

   Tests in Task 5.
5. **Stream without an LLM.** It still streams the grounded template answer and ends in `chat.complete`. Test in Task 6.

---

### Task 1: Gate script and bug fixes in pages (D4 page bugs, warnings)

**Files:**
- **Create:** `scripts/dev/gate.sh`. Promote the plan-local gate script, the same steps as Global Constraints, reading `.env` like `test-db.sh`.
- **Modify:**
  - `public/checkout.php`, `public/register.php`
  - `src/app/web/ProductPage.php`, `src/service/review/ReviewService.php`, `src/repository/ReviewRepository.php`
  - `src/app/web/admin/AdminUsersPage.php`, `AdminEditProductPage.php`, `AdminProductsPage.php`
  - `src/app/web/admin/AdminOrdersPage.php`

**Changes:**
- **Redirect after output.** `checkout.php` (guest) and `register.php` (success) must redirect before any output: move `sendIfFinal()` before the header include. The guest checkout redirect becomes a real 302 to `login.php`.
- **Reviews on `product.php`.** Use the correct join `r.user_id = u.id`. That is `ReviewService::forProductWithUsername` (Task 6 of Part B). Delete `forProductLegacyJoin` in the service and the repository.
- **Order status whitelist.** `AdminOrdersPage` rejects any status outside `['Chờ xử lý', 'Đang giao', 'Đã hoàn thành', 'Đã hủy']`, the same list as `src/app/api/controllers/Admin/order_status.php:12`. Move the list to one constant on `OrderService` (`OrderService::STATUSES`) and use it in both places. An invalid status redirects with no write.
- **Undefined `user_id` warning.** `AdminUsersPage` must not read an undefined `$session['user_id']`. An env admin has none, so the self-protection compares against `null`.
- **Missing upload part.** `AdminEditProductPage` and `AdminProductsPage` must not warn when the form arrives without a file part. Check `isset($files['image'])` first.

**Tests:**
- `ProductPageTest` reviews show the reviewer's username.
- `AdminOrdersPageTest`: an invalid status leaves the row unchanged.
- A unit test that checkout and register return a redirect `PageResult` for the guest/success case. The entry-file order is checked by the snapshot.

**Expected snapshot diff:**
- `08-guest-checkout` becomes a 302 with an empty body.
- `45-register-new-user` is a redirect.
- The `product.php?id=50` scenarios after the reviews are submitted show the correct reviewer names.
- Warnings disappear from the `zz-server-log-*` files.
- Nothing else.

### Task 2: One admin guard

**Files:**
- **Modify:**
  - `src/app/web/admin/AdminGuard.php` and all admin handlers;
  - `tests/unit/app/web/admin/AdminGuardTest.php`. Its per-page cases change by design; name them in the commit.

**Change:**
- `AdminGuard::check(array $session): ?PageResult` has one rule: `!empty($session['admin_logged_in']) || ($session['role'] ?? null) === 'admin'`. Otherwise it returns `redirect('login.php')`, which every admin page resolves to `public/admin/login.php`.
- Remove the `acceptAdminFlag` / `loginUrl` parameters.

**Expected snapshot diff:**
- Guest and customer admin scenarios whose `Location` was `../login.php` now show `login.php`.
- Nothing else.

### Task 3: CSRF tokens and POST for state changes (U2)

**Files:**
- **Create:**
  - `src/core/http/Csrf.php`: `final class Csrf` with `static token(array &$session): string` (lazily `bin2hex(random_bytes(32))` stored under `$session['csrf_token']`) and `static valid(array $session, mixed $given): bool` (`hash_equals`, false when either side is missing or not a string);
  - `templates/partials/csrf_field.php`: prints `<input type="hidden" name="csrf_token" value="...">`.
- **Modify:**
  - the handlers and templates for every action listed in U2;
  - `templates/pages/cart.php`: `updateCart()` and remove submit a POST form, not `window.location`;
  - `templates/pages/admin/*`: delete, lock, unlock and role become small POST forms;
  - `scripts/dev/web_snapshot.php`: scenarios read the token from the page and post it; add one scenario per action posting a wrong token; old GET URLs stay as scenarios to prove they change nothing.

**Handler rule:**
- A state-changing branch runs only for `method === 'POST' && Csrf::valid($session, $post['csrf_token'] ?? null)`.
- On a missing or invalid token, or on a GET to the old URL, nothing is written and the page answers the way the same page answers a no-op today (the same redirect target).
- The REST API under `/api` is token-authenticated (bearer). It is not cookie-CSRF-exposed, so it is out of scope.

**Tests:** a unit test per handler for each of these cases, asserting the DB is unchanged where nothing should be written:
- valid token: writes;
- missing token: no write;
- wrong token: no write;
- GET: no write.

**Expected snapshot diff:**
- Scenarios for the listed actions, now POST with a token. They should show the same end state as the baseline's GET versions.
- The new wrong-token and old-GET scenarios.
- The markup of pages that now carry forms or tokens. Tokens are normalised by `<hex64>`.
- Nothing else.

### Task 4: Keep size on re-add (U1) and block env-admin shopping (U3)

**Files:**
- **Modify:**
  - `src/service/cart/CartService.php` (`add`) and the cart repository method it uses;
  - `src/app/web/AddToCartAction.php`: pass `null` when `selected_size` is absent;
  - `src/app/web/PlaceOrderAction.php`;
  - `src/app/api/controllers/Cart/add.php`, only if it injects a default size today.

**Changes:**
- **`CartService::add` with a null or empty size:**
  - an existing line keeps its size, and only the quantity is merged;
  - a new line gets `S`.
- **`CartService::add` with a size:** behaviour is unchanged (uppercased, and it replaces the line's size).
- **Env admin.** In `AddToCartAction` and `PlaceOrderAction`, a session with `role === 'admin'` (or `admin_logged_in`) and no user id above 0 gets:
  - `output("<script>alert('Tài khoản quản trị không dùng để mua hàng.'); window.location.href='index.php';</script>")` from add to cart;
  - `halt('Tài khoản quản trị không dùng để mua hàng.')` from checkout.

**Tests:**
- `CartServiceTest::testReAddWithoutSizeKeepsLineSize`.
- `testReAddWithSizeReplacesIt`.
- `testAddWithoutSizeStoresS` (stays green).
- An action test for the env-admin message, for both actions.

**Expected snapshot diff:**
- `167-admin-env-add-to-cart` shows the new message.
- Customer add-to-cart scenarios only where a no-size re-add previously reset a size.
- Nothing else.

### Task 5: DB errors are 500s, not domain messages; no raw exception text

**Files:**
- **Create:** `src/service/cart/EmptyCartException.php` (`extends RuntimeException`, message `'Cart is empty'`).
- **Modify:**
  - `OrderService::create`: throws `EmptyCartException` where it throws `'Cart is empty'` today;
  - `PlaceOrderAction`: catches `EmptyCartException`, not a message match;
  - `src/app/api/controllers/Cart/*.php` and `Orders/*.php`;
  - `src/app/web/OrderDetailsPage.php`, `AddToCartAction.php`, `PlaceOrderAction.php`, `admin/AdminDashboardPage.php`.

**Changes:**
- **API controllers** catch `PDOException` before any `RuntimeException`, log it with `error_log`, and answer `errorResponse('Lỗi hệ thống, vui lòng thử lại sau.', 500)`. Domain errors keep their current codes and messages.
- **Web handlers** catch `PDOException` first. They log it and `halt('Lỗi hệ thống, vui lòng thử lại sau.')`, and never print `$e->getMessage()`.
  - `OrderDetailsPage` no longer turns a DB error into "not found".
  - `AddToCartAction` no longer turns a DB error into "hết hàng".
  - `PlaceOrderAction`'s "other Throwable" branch prints the generic message instead of the raw text.

**Tests:** unit tests on SQLite with a dropped table, one per changed path. They assert the generic message, that the SQL text is absent, and the HTTP code for the API.

**Expected snapshot diff:** none. The snapshot has no DB-failure scenario.

### Task 6: Stream without an LLM streams the template answer

**Files:** to be found by diagnosis, starting from `src/app/api/controllers/Chatbot/stream.php:65`, `src/agent/ChatbotService.php`, `src/harness/answer/*` and `AnswerTemplateFallback`.

**Problem:** with no LLM configured and `CHATBOT_TEMPLATE_RESPONSE` off, `POST /api/chatbot/stream` ends in `chat.error` (`STREAM_FAILED`), while `POST /api/chatbot` answers. The required `AnswerTemplateFallback` middleware promises "the turn never fails because of the LLM".

**Approach:**
1. Diagnose with superpowers:systematic-debugging.
2. Write a failing test that reproduces it: a unit test on the stream path with no provider.
3. Fix it so that the grounded draft streams and the stream ends in `chat.complete` with `latency.streaming=template_fallback`.

**Gate:** the eval stays 40/40, and `node scripts/smoke_chat_stream_latency.mjs` still passes on the local stack.

**Expected snapshot diff:** none. The snapshot does not cover chatbot POST.

### Task 7: MCP bridge only executes catalogued tools; docs

**Files:**
- **Modify:**
  - `src/agent/AgentFactory.php` (`executeTool`);
  - `tests/unit/agent/*`;
  - the spec §7 step 11 list (mark each item resolved, with its task);
  - `CLAUDE.md` Hard rules (the CSRF and size behaviour, in one line each);
  - `.claude/rules/testing.md` (`scripts/dev/gate.sh`).

**Change:**
- `executeTool` refuses any tool not listed in `ToolDefinitionCatalog`, the same filter `ToolRegistry::execute` applies.
- The exception type must map to 400 in `Internal/mcp.php`, as unknown-tool input does today. Check what an unknown tool produces now and keep that code.
- Cart and checkout tools can then run through neither path.

**Test:** `executeTool('add_to_cart', ...)` and `executeTool('create_order', ...)` are refused, and a catalogued tool still runs.

**Expected snapshot diff:** none.
