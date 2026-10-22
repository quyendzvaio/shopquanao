# Layered refactor, Part B (website) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every SQL statement lives in `src/repository/`. Business logic lives in `src/service/`. Each storefront and admin page becomes a thin entry file over a handler in `src/app/web/` and a template in `templates/pages/`. Web checkout and add-to-cart use the same logic as the API. Apart from that, nothing a browser or API client sees changes.

**Architecture:**
- **Task 1** builds the safety net: an HTTP snapshot harness, which is the byte-for-byte oracle for every page and API response.
- **Task 2** adds characterization tests for the existing services.
- **Task 3** turns the layer rules into a ratcheting test. The test fails on any new violation, and each later task shrinks its allowlist.
- **Tasks 4–8** do one domain at a time. Each adds repositories, routes the services and REST controllers through them, and moves env reads into `core/config`.
- **Tasks 9–12** split the pages into handler + template, one page group at a time, with zero snapshot diff.
- **Task 13** is the one deliberate behaviour change (checkout and add-to-cart).
- **Task 14** empties the allowlists and updates the docs.

**Tech Stack:** PHP 8.2 (Composer classmap, no namespaces), PHPUnit, PHPStan level 1, MariaDB 10.11 (`shop_test`), PHP built-in server + ext-curl inside the app image, Docker wrappers `scripts/dev/php` and `scripts/dev/composer`.

**Spec:** `docs/superpowers/specs/2026-10-22-layered-refactor-design.md`. Part B covers §3, §3.1, §5, §6 and §7 steps 7–12.

**Inventory (read before your task):**
- `docs/superpowers/plans/2026-10-22-website-layers-inventory/sql-inventory.md` lists every SQL call site. For each one it gives the proposed repository method, duplicate groups, transactions and dynamic SQL.
- `docs/superpowers/plans/2026-10-22-website-layers-inventory/web-survey.md` covers each page: its guard, redirects and exact copy. It also has the checkout comparison, the test inventory and the env reads.
- Method names in the inventory are proposals. Each task's **Interfaces** block below is binding where they differ.

## Global Constraints

- **No test expectation changes.** No existing assertion and none of the 40 eval cases may change. Tests may only change paths and the lines that construct or call a moved or re-signatured class.
- **Byte-identical output.** Each task in 1–12 ends with an **empty** snapshot diff against `reports/web-snapshot/baseline`. Task 13 is the only one allowed a diff, and only the one it lists.
- **SQL text moves verbatim.** The exceptions are the checkout N+1 (Task 13) and `public/order_details.php` (Decision D4). Driver branches (`FOR UPDATE`, `ON CONFLICT` vs `ON DUPLICATE KEY`, `NOW()` vs `CURRENT_TIMESTAMP`, `FIELD()`) move into the repository method unchanged.
- **Repository shape:** `final class XRepository { public function __construct(private PDO $pdo) {} }`, the same as `src/repository/OutfitCatalogRepository.php`.
  - One query per method, named for what it does.
  - A `try/catch` that swallows a missing optional table stays exactly where it is today, in the same layer.
- **Services keep their current constructors**, e.g. `new CartService($pdo)`, and build their repositories internally. A service may call `$pdo->beginTransaction()/commit()/rollBack()/inTransaction()`. It may never call `prepare`, `query` or `exec`. Transactions stay in the service that owns them today (`OrderService::create`, `CartService::add`).
- **Layer rules (spec §3.1):**

  | Layer | May use |
  |---|---|
  | `app` | `agent`, `service`, `model`, `core` |
  | `agent` | `harness`, `llm_providers`, `service`, `repository`, `model`, `core` |
  | `service` | `repository`, `model`, `core` |
  | `repository` | `model`, `core` |
  | `model`, `core` | nothing |

  Env reads (`getenv`, `$_ENV`) happen only in `agent/` and `core/`. `$_SERVER` is never read in `harness/`, `service/`, `repository/` or `model/`.
- No change to: HTTP API, URLs, DB schema, HTML/CSS, `mcp-server/`. No new migration.
- **Full gate after every commit.** Every command must exit 0:
  ```bash
  scripts/dev/composer dump-autoload -q
  scripts/dev/php vendor/bin/phpunit --testsuite=Unit
  scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
  scripts/dev/test-db.sh && scripts/dev/test-integration.sh
  DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="${MARIADB_ROOT_PASSWORD:-root_pass}" scripts/dev/php scripts/run_agent_eval.php
  npm --prefix mcp-server test
  scripts/dev/web-snapshot.sh current && diff -r reports/web-snapshot/baseline reports/web-snapshot/current
  ```
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. **Never** commit `.env`, `reports/`, `output/` or `docs/*.docx`. **Never** run `docker compose down` or `--remove-orphans`.
- **Files are mostly CRLF and often end without a newline after the closing `?>`.** Move markup with byte-preserving tools (`sed -n 'a,bp'`), not an editor that normalises line endings. The snapshot diff is the judge, not the source diff.

## Decisions (rulings made while planning; listed in the PR)

- **D1.** The layer test lands early (Task 3) with allowlists that may only shrink. Spec §7 puts it at step 11. Landing it early makes every task's progress checkable. Task 14 empties the allowlists, which is spec step 11.
- **D2.** Spec steps 8 (repository) and 9 (service) are done per domain inside one task. A repository with no caller would violate the spec's anti-goal ("no abstraction without a real user").
- **D3.** `harness/memory` may use `repository`. `harness/middleware` may use only `ChatMemoryRepository`. Spec §9.2 says the harness calls down to service/repository. Part A's test was stricter than the spec.
- **D4.** Pre-existing page bugs are kept verbatim and listed as follow-ups. Fixing them here would break byte identity and the spec's "one behaviour change" rule. The list:
  - redirect after output on `checkout.php` (guest) and `register.php` (success), where `output_buffering=0`;
  - the `reviews r JOIN users u ON r.id = u.id` join on `product.php`;
  - inconsistent admin login redirect targets;
  - no status whitelist in `admin/manage_orders.php`;
  - cart updates through GET.

  **Exception:** `order_details.php` queries a table `order_details` that does not exist, so every valid order returns HTTP 500 today and there is nothing to preserve. Task 10 points it at `OrderService::detail()` (`order_items`).
- **D5.** Orders already stored as `pending` are not migrated (spec: no DB change). Only new web orders get `Chờ xử lý`.
- **D6.** Two core config classes become the only env readers outside `agent/`:
  - `core/config/ServiceConfig` for knowledge, reranker and free-ship settings;
  - `core/config/AppConfig` for allowed origins, max message length, the MCP token and admin credentials.

  `FreeShippingPolicy::fromEnvironment()` is removed. `HarnessConfig` builds the policy from `ServiceConfig`, and the tool path receives that same policy (closes the spec §7 step 11 carry-over).
- **D7.** Chatbot controllers stop referencing `ChatbotSessionContext` and `LLMTransportException`. They use `ChatbotService::open()`, `sessionToken()`, `touchSession()` and `ChatbotService::errorCategory()`.
- **D8.** Upload and unlink file I/O stays in the admin page handlers in `app/web`, because it handles request I/O. Services receive file names only.
- **D9.** The admin guard now runs after `bootstrap.php`, which opens the DB connection, where today it runs before. This is observable only when the DB is down. The `add_product.php` exit fix is kept: the handler returns the redirect before any upload or insert.
- **D10.** Pages with no SQL and no business logic (`logout.php`, `admin/logout.php`) stay unchanged.

## Review Focus

1. **Byte drift from moving markup** (CRLF, the newline PHP eats after `?>`, a trailing newline at end of file). Expected: zero diff. The snapshot harness covers every page as guest, user and admin (Task 1).
2. **Guard targets and order.** A guest hitting each guarded page must get the same status and `Location` (`login.php`, `../login.php`, `index.php`), and `checkout.php` keeps its header-then-redirect order. Task 1 has a scenario for each guarded page.
3. **Overselling the last unit.** Two orders for the last unit: the second must fail and leave stock at 0. Test: `OrderServiceTest::testSecondOrderForLastUnitFails` (Task 2).
4. **Admin logged in from env** (no `user_id`, or `user_id=0`) on pages that read `user_id`, i.e. `manage_users` self-protection and `add_to_cart`. Expected: same output as baseline. Snapshot scenario `admin-env-*` (Task 1).
5. **Size after the checkout fix.**
   - Freesize products: `CartService` stores `FREESIZE`, and the cart page must still pre-select "Freesize".
   - Adds from the home page post no size and must store `S`.
   - Tests: Task 13 scenarios `add-freesize` and `add-from-home`, plus `CartServiceTest::testAddWithoutSizeStoresS`.

---

### Task 1: HTTP snapshot harness and baseline

**Files:**
- Create: `scripts/dev/web-snapshot.sh`, `scripts/dev/web_snapshot.php`, `scripts/dev/web_router.php`

**Interfaces:**
- Produces: `scripts/dev/web-snapshot.sh <label>`. It writes one file per scenario to `reports/web-snapshot/<label>/NN-<scenario>.txt` and exits non-zero if any scenario could not run. Every later task uses it as its gate.

- [ ] **Step 1: Write `scripts/dev/web_router.php`.** It is a router for `php -S`:
  - `/api/*` goes to `require public/api/index.php`.
  - An existing file under `public/` gets `return false`, so the built-in server serves it.
  - Anything else falls through to `public/index.php`, the same as the nginx catch-all.

- [ ] **Step 2: Write `scripts/dev/web_snapshot.php <outdir>`**, which runs inside the app image. In order:
  1. Seed `shop_test` through PDO, using `DB_*` from the environment:
     - customer `snap-user@example.test` / `snap-pass-1`;
     - customer `snap-other@example.test`;
     - DB admin `snap-admin` / `snap-admin-pass` (`role='admin'`);
     - two cart lines for the customer (product 50 qty 1, a second product qty 2).
  2. Start `php -S 127.0.0.1:8099 -t public scripts/dev/web_router.php` with `proc_open`. Its env adds `ADMIN_EMAIL=snap-env-admin@example.test` and `ADMIN_PASSWORD=snap-env-pass`, only for the `admin-env-*` scenarios. Run those last, on a second server started with that env, and the rest on a server without it.
  3. Run the scenarios with ext-curl and per-actor cookie jars. Each output file holds `status: <code>`, `location: <header or ->`, a blank line, then the body.
  4. Normalise before writing:
     - 64-hex tokens → `<hex64>`;
     - `YYYY-MM-DD HH:MM:SS` → `<ts>`;
     - `d/m/Y` dates → `<date>`;
     - `PHPSESSID` values.
  5. Stop the server.

  **Scenarios** (keep this order: later ones depend on earlier state):
  - **Guest:**
    - `GET /`, `/?search=áo`, `/?category=1`
    - `/product.php?id=50`, `/product.php?id=999999`
    - `/cart.php`, `/checkout.php`, `/profile.php`, `/order_details.php?id=1`
    - `/login.php`, `/register.php`
    - `POST /add_to_cart.php`
    - `/admin/index.php`, `/admin/manage_products.php`, `/admin/manage_users.php`, `/admin/manage_orders.php`, `/admin/edit_product.php?id=50`, `/admin/login.php`
    - API: every unauthenticated GET in `src/app/api/routes.php`. Use the query strings from `scripts/dev/smoke.sh`, plus `/api/products?search=áo thun&sort=price_asc&limit=5` and `/api/faq?search=đổi`.
  - **Register:** `POST /register.php` with mismatched passwords; with an existing email; with a new user.
  - **Customer:**
    - `POST /login.php` with a wrong password, then the right one.
    - `GET /cart.php`, `/checkout.php`, `/profile.php`, `/profile.php?status=Đang giao`.
    - `POST /add_to_cart.php` for product 50 (action add), then buy_now, then an out-of-stock product.
    - `GET /update_cart.php?id=<line>&value=3&type=qty`, then `&value=M&type=size`, then `/cart.php?remove=<line>`.
    - `POST /submit_review.php`, then `GET /product.php?id=50`.
    - `POST /process_checkout.php`.
    - `GET /order_details.php?id=<new order id>`, which returns 500 today.
    - API with the token from `POST /api/auth/login`: `GET /api/cart`, `POST /api/cart`, `GET /api/orders`, `GET /api/auth/me`.
  - **DB admin:**
    - `POST /admin/login.php` with a bad password, then the right one.
    - `GET` `index`, `manage_products`, `manage_orders`, `manage_users`, `edit_product.php?id=50`, `add_product.php`.
    - `POST manage_orders` `update_status` to `Đang giao`.
    - `GET manage_users.php?action=lock&id=<snap-other>`, then `action=unlock`, then `toggle_role=admin&id=<snap-other>`.
    - `POST edit_product.php?id=50` without an image.
    - `POST add_product.php` without an image (the upload-error path).
    - `GET manage_products.php?delete_id=<a product seeded for deletion>`.
    - API with an admin token: every `/api/admin/*` GET, plus `PATCH` order status.
  - **Env admin** (second server): `POST /login.php` with the env credentials, then `GET /admin/index.php`, `/admin/manage_users.php`, `/add_to_cart.php` (POST).
  - **Never** post a real image file: it would write into `public/assets/images/`.

- [ ] **Step 3: Write `scripts/dev/web-snapshot.sh <label>`.** It runs `scripts/dev/test-db.sh` (fresh `shop_test` + migrations). Then it runs `DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS=... scripts/dev/php scripts/dev/web_snapshot.php reports/web-snapshot/<label>`. Read `DB_PASS` the same way `test-db.sh` does.

- [ ] **Step 4: Check determinism.**
  Run: `scripts/dev/web-snapshot.sh baseline && scripts/dev/web-snapshot.sh again && diff -r reports/web-snapshot/baseline reports/web-snapshot/again`
  Expected: no output. If something differs, extend the normalisation (only for values that are random or time-based) until two runs match.

- [ ] **Step 5: Sanity-check the baseline.**
  - `grep -l '^status: 500' reports/web-snapshot/baseline/*` must list only the `order_details` customer scenario.
  - The guest `cart.php` scenario must show `location: login.php?error=vui_long_dang_nhap`.

- [ ] **Step 6: Commit.** `git add scripts/dev/web-snapshot.sh scripts/dev/web_snapshot.php scripts/dev/web_router.php && git commit -m "test(web): HTTP snapshot harness for pages and API"`. The baseline stays in `reports/` (gitignored). Later tasks diff against it. Only Task 13 re-baselines.

### Task 2: Characterization tests for Cart, Order and Product services

**Files:**
- Create: `tests/integration/service/CartServiceTest.php`, `tests/integration/service/OrderServiceTest.php`, `tests/integration/service/ProductServiceTest.php`

**Interfaces:**
- Consumes: `CartService`, `OrderService`, `ProductService` as they are today (signatures are in web-survey Part 2).
- Pattern: skip when `DB_HOST` is unset, like `tests/integration/CatalogColorSearchTest.php`.
  - `setUp` creates one throwaway user.
  - `tearDown` deletes that user's cart, orders and order_items rows, and restores any product stock the test changed.

- [ ] **Step 1: Write the tests.** They pin today's behaviour:
  - **`OrderServiceTest`:**
    - `testCreateDecrementsStockWritesPendingStatusAndClearsCart`: 2 × product P. Asserts:
      - the result has `message === 'Order placed successfully'` and `total_price === round(2*price, 2)`;
      - the row has `orders.status === 'Chờ xử lý'`;
      - one `order_items` row with the locked price;
      - stock is reduced by 2;
      - the cart is empty.
    - `testCreateRefusesQuantityAboveStock`: `RuntimeException('Insufficient stock for product P')`; stock, cart and order count unchanged.
    - `testCreateOnEmptyCartThrows`: `'Cart is empty'`.
    - `testSecondOrderForLastUnitFails`: stock 1. Two users each have it in their cart. The first create succeeds, the second throws, and final stock is 0.
    - `testListFiltersByStatusAndCountsCompleted`.
    - `testDetailOfAnotherUsersOrderThrows`: `'Order not found'`.
  - **`CartServiceTest`:**
    - `testAddStoresUppercasedSizeAndMergesQuantity`
    - `testAddWithoutSizeStoresS`
    - `testAddRefusesMergedQuantityAboveStock`: `'Insufficient product stock'`
    - `testAddUnknownProduct`: `'Product not found'`
    - `testAddRejectsNonPositiveQuantity`: `InvalidArgumentException('product_id and a positive quantity are required')`
    - `testUpdateQuantityAndSize`
    - `testRemoveMissingLineThrows`: `'Cart item not found'`
  - **`ProductServiceTest`:**
    - `search()` with category + price range; `search()` with a free-text term; `detail(['product_id' => 50])`; `categories()`.
    - Assert the ids, order and keys **as they come back today**: run once against the unmodified code and write the observed values into the assertions.

- [ ] **Step 2: Run them.** `scripts/dev/test-db.sh && scripts/dev/test-integration.sh`. Expected: OK. The tests describe current code, so they pass immediately; that is the point. Run them a second time without resetting the DB to prove the cleanup works.

- [ ] **Step 3: Commit.** `test(service): characterize cart, order and product services`.

### Task 3: Layer rules as a ratcheting architecture test

**Files:**
- Modify: `tests/unit/ArchitectureTest.php`, `src/harness/README.md` (dependency table: D3)

**Interfaces:**
- Produces constants that later tasks edit, removing entries only:
  - `LEGACY_SQL` (list of repo-relative paths);
  - `LEGACY_ENV` (list of paths);
  - `LEGACY_EDGES` (path → list of class names).
- Permanent allowlist `SQL_EXCEPTIONS` = `src/core/database/MigrationRunner.php`, `src/core/observability/LangfuseTraceOutbox.php`, `src/core/observability/LangfuseOtlpTracePublisher.php`. The publisher reads and updates the same outbox table, so it is the same infrastructure exception as in spec §3.1.

- [ ] **Step 1: Add the tests.**
  - `testLayerDependenciesFollowTable`:
    - Covers every `src/**/*.php` outside `src/harness`; the harness keeps its component test.
    - Maps every harness component to the layer `harness`.
    - The table is in Global Constraints, plus `llm_providers → harness(llm only), core`.
    - Violations listed in `LEGACY_EDGES` are skipped.
  - `testSqlOnlyInRepositories`:
    - A token scan of `src/**`, `public/**` and `templates/**` for `T_OBJECT_OPERATOR` followed by `T_STRING` in `prepare|query|exec`. `strippedCode()` removes strings, so it cannot see SQL text; use tokens.
    - Allowed in: layer `repository`, `SQL_EXCEPTIONS`, `LEGACY_SQL`.
  - `testEnvReadOnlyInAgentAndCore`:
    - `getenv(` and `$_ENV` are forbidden in `src/**` outside `agent/` and `core/`, and in `public/**` and `templates/**`.
    - `$_SERVER` is forbidden in `harness/`, `service/`, `repository/` and `model/`.
    - Allowed: `LEGACY_ENV`.
  - `testLegacyAllowlistsStillApply`: every legacy entry must still violate its rule, so a fixed file has to be removed from the list. The ratchet only shrinks.
  - Extend `testComponentMapResolvesHarnessAndOutsideClasses` with one known class per layer (`CartService` → service, `OutfitCatalogRepository` → repository, `StyleTag` → model) so the new tests cannot pass vacuously.
  - D3: add `repository` to `ALLOWED['memory']`. Set `LIMITED['middleware']['repository'] = ['ChatMemoryRepository']`.

- [ ] **Step 2: Fill the allowlists from the failures.** Run `scripts/dev/php vendor/bin/phpunit tests/unit/ArchitectureTest.php`. Each new test fails with today's violations. Copy them into the `LEGACY_*` constants, sorted.
  - Cross-check against sql-inventory §0: 52 files, plus `templates/partials/header.php`.
  - Cross-check against web-survey Part 4: env reads.
  - `LEGACY_EDGES` must contain at least `Chatbot/index.php` and `Chatbot/stream.php`, and every controller that uses `PDO` directly only through SQL is covered by `LEGACY_SQL`, not by edges.

  Then rerun. Expected: OK.

- [ ] **Step 3: Update the dependency table in `src/harness/README.md`** for `memory` and `middleware` (D3).

- [ ] **Step 4: Run the full gate.** Commit `test(arch): enforce layer, SQL and env rules with a shrinking legacy allowlist`.

### Task 4: Catalog domain (products, categories, sizes, variants)

**Files:**
- Create in `src/repository/`: `ProductRepository.php`, `CategoryRepository.php`, `SizeRepository.php`, `VariantRepository.php`
- Create in `src/model/`: `ProductSearchCriteria.php`, `ProductListFilter.php`
- Create: `src/service/catalog/CategoryService.php`
- Modify:
  - `src/service/catalog/{ProductService,CatalogVariantHydrator,CatalogVariantBackfill}.php`
  - `src/service/size/SizeService.php`
  - `src/app/api/controllers/{Products/list,Products/detail,Products/sizes,Categories/list,SizeGuide/index}.php`
- Test: `tests/unit/repository/{ProductRepository,SizeRepository}Test.php` (SQLite, for SQL that runs on SQLite)

**Interfaces:**
- **`ProductRepository`:**
  - Methods from sql-inventory §6 that have a caller in this task, plus `search(ProductSearchCriteria $c): array`.
  - `listPage(ProductListFilter $f, string $sort, ?int $limit, int $offset): array{rows: list<array>, total: int}`. The WHERE/ORDER builder from `Products/list.php` moves here unchanged, including the FULLTEXT/LIKE branch.
  - Methods that only pages or admin use come in Tasks 6, 9 and 12.
- **`ProductSearchCriteria`** (model, readonly): `categoryId`, `subcategory`, `minPrice`, `maxPrice`, `inStock`, `size`, `color` (nullable). `ProductService::executeSearchProductsDirect` builds it, calls `search()` **once**, and reuses the rows for the "all words" fallback. Today it runs the same SQL twice, so the result is identical.
- **`ProductListFilter`** (model, readonly): the normalised values `Products/list.php` computes today (`ftsQuery`, `likeTerm`, `tokens`, `category`, `subcategory`, `minPrice`, `maxPrice`, `inStock`, `color`). Search-term parsing moves to `ProductService::listPage(array $query): array`, which returns exactly what the controller encodes today.
- **`CategoryService`:**
  - `all(): array` for the API list order;
  - `taxonomy(): array`;
  - `withSubcategories(): array` (used by admin in Task 12).
- **`SizeService`** gains `guides(?int $categoryId): array` for `SizeGuide/index.php`.

- [ ] **Step 1: Write the repository unit tests on SQLite** for the queries that run there: `findById`, `sizesForProduct`, `search` with each criterion. Use `SqliteShop` and add any missing columns inside the test, the same way `OutfitCatalogRepositoryTest` does. Run them; they fail (class missing).
- [ ] **Step 2: Create the repositories and models.** Move each listed query verbatim, then reroute the callers in the service and controller files listed above. Controllers keep their response code and shape.
- [ ] **Step 3: Remove from `LEGACY_SQL`** every file that no longer issues SQL.
- [ ] **Step 4: Run the full gate including the snapshot diff.** Expected: green and an empty diff. Commit `refactor(catalog): product, category, size and variant repositories`.

### Task 5: Cart and order domain

**Files:**
- Create: `src/repository/CartRepository.php`, `src/repository/OrderRepository.php`
- Modify:
  - `src/service/cart/CartService.php`, `src/service/order/OrderService.php`
  - `src/app/api/controllers/Admin/{orders,order_status}.php`
  - the order counts in `Admin/dashboard.php`

**Interfaces:**
- **`CartRepository`:** the methods in sql-inventory §6 that `CartService` uses, plus `totalQuantity(int $userId): ?int` (the `header.php` query, used in Task 9).
- **`OrderRepository`:**
  - `cartLinesForCheckout(int $userId, bool $forUpdate): array`. The `FOR UPDATE` suffix is appended inside, only when `$forUpdate`. `OrderService` passes `driver === 'mysql'`, as it computes today.
  - `insertOrder(int $userId, float $total): int`, `insertItem(int $orderId, int $productId, int $qty, float $price): void`.
  - `listForUser`, `completedStats`, `findForUser`, `itemsForOrder`, `count`, `completedRevenue`, `allWithUsername`, `updateStatus(int $id, string $status): int`, `recentPurchases(int $userId): array`.
- **`ProductRepository::decrementStock(int $id, int $qty): bool`** returns `rowCount() === 1`. `OrderService` throws `'Stock changed while creating the order'` on false, as today.
- **`OrderService`** gains `allWithUsername(): array`, `updateStatus(int $orderId, string $status): int`, `count(): int` and `completedRevenue(): float` for admin.
- **`CartService`** gains `itemCount(int $userId): int`.
- Transactions stay in `OrderService::create` and `CartService::add`.

- [ ] **Step 1:** Task 2's `CartServiceTest` and `OrderServiceTest` are the tests here. Add `OrderServiceTest::testAdminStatusUpdateReturnsAffectedRows` (pins `rowCount` semantics, including 0 when the status is the same on MySQL).
- [ ] **Step 2:** Move the queries and reroute the callers. Shrink `LEGACY_SQL`.
- [ ] **Step 3:** Run the full gate with the snapshot diff. Commit `refactor(order): cart and order repositories`.

### Task 6: Users, reviews, FAQ and legacy outfit suggestions

**Files:**
- Create: `src/repository/{UserRepository,ReviewRepository,FaqRepository,OutfitSuggestionRepository}.php`
- Create: `src/service/user/UserService.php`, `src/service/review/ReviewService.php`, `src/service/faq/FaqService.php`, `src/service/admin/AdminDashboardService.php`, `src/service/catalog/ProductAdminService.php`
- Modify:
  - `src/app/api/middleware.php` (`authenticate()`)
  - `src/app/api/controllers/{Auth/*,Admin/*,Products/add_review,Products/reviews,Products/detail,Faq/index,Outfit/index}.php`
  - `src/service/outfit/OutfitService.php`
  - `src/service/knowledge/KnowledgeRetriever.php` (its FAQ and size-guide reads)
- Test: `tests/integration/service/UserServiceTest.php`, `tests/integration/service/ReviewServiceTest.php`

**Interfaces:**
- **`UserService`:**
  - `userForApiToken(string $token): ?array` (used by `authenticate()`)
  - `registerViaApi(...)` and `loginViaApi(...)`: same returns as the Auth controllers build today
  - `logout(int $userId): void`
  - `adminList(): array`
  - `setStatus(int $id, int $status): int`
  - `setRole(int $id, string $role): int`
  - `count(): int`

  Storefront and admin login variants are added in Tasks 11 and 12, each with its own verbatim query.
- **`ReviewService`:** `forProductWithUsername(int $productId): array`, `ratingSummary(int $productId): array`, `add(int $productId, int $userId, int $rating, string $comment): void`.
- **`FaqService::search(?string $category, string $search): array`.**
- **`OutfitService::suggestions(?int $productId, string $search): array`** reads `outfit_suggestions` through `OutfitSuggestionRepository`. The existing UC-A `OutfitCatalogRepository` is unchanged.
- **`AdminDashboardService::stats(): array`** combines the product, order and user counts and the revenue for `Admin/dashboard.php` and `admin/index.php`.
- **`ProductAdminService`** (`src/service/catalog/`): `create(array $fields): int`, `update(int $id, array $fields): int`, `delete(int $id): int`, `imageOf(int $id): ?string`, `allNewestFirst(): array`.
  - `update` keeps today's column whitelist from `Admin/product_update.php`, inside `ProductRepository::updateFields`.
  - These are used by the `Admin/product_*` controllers here and by the admin pages in Task 12.

- [ ] **Step 1:** Write `UserServiceTest`: token lookup, register duplicate email, set status/role. Write `ReviewServiceTest`: add, then list with username, then the rating summary. Run them; they fail.
- [ ] **Step 2:** Implement, reroute the controllers, and shrink `LEGACY_SQL` and `LEGACY_EDGES`.
- [ ] **Step 3:** Run the full gate with the snapshot diff. Commit `refactor(account): user, review, faq and admin services over repositories`.

### Task 7: Chat persistence and the agent facade for controllers

**Files:**
- Create: `src/repository/{ChatSessionRepository,ChatConversationRepository,ChatMemoryRepository}.php`, `src/service/chat/ChatHistoryService.php`
- Modify:
  - `src/harness/memory/{ChatbotMemory,ChatbotSessionContext,PdoChatbotConversationStore}.php`
  - `src/harness/middleware/FreeShippingNudge.php`
  - `src/agent/{AgentFactory,ChatbotService}.php`
  - `src/app/api/controllers/Chatbot/{index,stream,history}.php`

**Interfaces:**
- **Repositories:** the methods are as sql-inventory §4.5 and §6, with two rules:
  - The role literal stays in the SQL, so there are two methods, `addUserMessage` and `addBotMessage`.
  - The driver branches live inside `saveSession`, `saveLongTerm` and `touchUpdatedAt`.

  `ChatMemoryRepository::ensureTables(): void` keeps the runtime `CREATE TABLE IF NOT EXISTS`.
- **`ChatbotMemory`, `PdoChatbotConversationStore` and `ChatbotSessionContext`** build their repositories from the PDO they receive today. Their public signatures do not change.
- **`FreeShippingNudge`** takes a `ChatMemoryRepository` in place of `PDO` in its constructor. `AgentFactory::makeMiddleware` builds it (D3).
- **`ChatbotService`:**
  - `public static function open(PDO $pdo, ?string $sessionToken, ?string $bearerToken): self` resolves the context (today's code in `Chatbot/index.php`) and constructs the service.
  - `sessionToken(): string`, `sessionId(): int`, `touchSession(): void`.
  - `public static function errorCategory(Throwable $e): string` returns `$e->category` for `LLMTransportException`, otherwise `'runtime_failure'`.
- **`ChatHistoryService::forRequest(?string $sessionToken, ?string $bearerToken): array`** returns exactly what `Chatbot/history.php` returns today.

- [ ] **Step 1:** Add `tests/unit/repository/ChatMemoryRepositoryTest.php` on SQLite: `saveSession`, then `sessionMemory`, then `updateSlots` / `insertSlots`, then `slotsJson`. Run it; it fails.
- [ ] **Step 2:** Implement and reroute. `FreeShippingNudgeTest` and `FreeShippingNudgeMiddlewareTest` change only their construction lines.
- [ ] **Step 3:** Remove the `Chatbot/*` entries from `LEGACY_EDGES` and the chat files from `LEGACY_SQL`. Run the full gate with the snapshot diff. Commit `refactor(chat): chat repositories; controllers use the agent facade`.

### Task 8: Configuration out of services, model and app

**Files:**
- Create: `src/core/config/ServiceConfig.php`, `src/core/config/AppConfig.php`
- Modify:
  - `src/service/knowledge/{KnowledgeRetriever,KnowledgeService}.php`
  - `src/service/catalog/{ProductReranker,ProductService}.php`
  - `src/service/freeship/FreeShippingService.php`
  - `src/model/FreeShippingPolicy.php`
  - `src/harness/tools/{ToolApplicationService,ToolRegistry}.php`
  - `src/agent/{HarnessConfig,AgentFactory}.php`
  - `src/app/api/support/ChatStreamProtocol.php`
  - `src/app/api/controllers/{Internal/mcp,Knowledge/search}.php`
  - `scripts/{ingest_knowledge,run_agent_eval}.php`, `eval/AgentEvalCaseRunner.php`
- Test: `tests/unit/core/config/ServiceConfigTest.php`, `AppConfigTest.php`. Reuse `tests/unit/agent/EnvSandbox.php`.

**Interfaces:**
- **`ServiceConfig`** (final, readonly public properties) with `fromEnvironment(): self`. Defaults are copied from today's code:
  - `qdrantUrl`, `ragMlUrl`, `knowledgeCollection`, `embeddingProvider`, `embeddingModel`, `knowledgeHybridCandidates`, `knowledgeVersion` (`'v1'`), `knowledgeRerankerModel` (`'itdainb/PhoRanker'`);
  - `rerankerUrl` (nullable);
  - `freeShippingThreshold` (500000), `freeshipNudgeMaxGap` (200000).
- **`AppConfig::fromEnvironment(): self`**: `allowedOrigins` (list), `maxMessageChars` (2000), `mcpServiceToken`, `adminEmail` (`'admin@gmail.com'`), `adminUser`, `adminPassword` (`''`).
- **Constructor changes:**
  - `KnowledgeRetriever(PDO $pdo, ServiceConfig $config, ?string $rootDir = null)`
  - `KnowledgeService(PDO $pdo, ServiceConfig $config)`
  - `ProductReranker(?string $url)`
  - `ProductService(PDO $pdo, ?int $userId = null, ?string $rerankerUrl = null)`
  - `FreeShippingService(PDO $pdo, FreeShippingPolicy $policy)`: the policy is now required.
  - `ToolApplicationService(PDO $pdo, ?int $userId, ServiceConfig $config, FreeShippingPolicy $freeShipping)`
  - `ToolRegistry(PDO $pdo, ?int $userId, ServiceConfig $config, FreeShippingPolicy $freeShipping)`
- **`HarnessConfig`** gains `public ServiceConfig $services`. Its `fromEnvironment()` builds `freeShipping` as `new FreeShippingPolicy($services->freeShippingThreshold, $services->freeshipNudgeMaxGap)`.
- **`FreeShippingPolicy::fromEnvironment()` and `intEnv()` are deleted.** Callers use `HarnessConfig::fromEnvironment()->freeShipping`.
- **`ChatStreamProtocol`** takes its limits from `AppConfig`: `rejectBrowserRequest(string $message, array $server, AppConfig $config)`. `Internal/mcp.php` reads the token from `AppConfig` and passes `HarnessConfig::fromEnvironment()` services and policy into `ToolApplicationService`.

- [ ] **Step 1: Write the failing tests.**
  - `ServiceConfigTest::testDefaultsMatchPreviousEnvFallbacks` and `testReadsEachVariable`.
  - `AppConfigTest`: the same pair.
  - `tests/unit/harness/tools/ToolRegistryDelegationTest.php::testFreeshipToolUsesInjectedPolicy`: the injected threshold is 750000 and the tool result reflects it. This closes the spec carry-over.
- [ ] **Step 2:** Implement. Update the construction lines in tests, `eval/` and `scripts/`. Remove every `LEGACY_ENV` entry except `public/login.php` and `public/admin/login.php`, which Tasks 11 and 12 remove.
- [ ] **Step 3:** Run the full gate with the snapshot diff. Eval must stay 40/40, and it reads the policy through `HarnessConfig`. Commit `refactor(config): env reads live in core/config and agent only`.

### Task 9: Web handler scaffolding and catalog pages

**Files:**
- Create in `src/app/web/`: `PageResult.php`, `WebRequest.php`, `HomePage.php`, `ProductPage.php`
- Create in `templates/pages/`: `home.php`, `product.php`
- Modify: `public/index.php`, `public/product.php`, `templates/partials/header.php`
- Test: `tests/unit/app/web/PageResultTest.php`, `tests/unit/app/web/ProductPageTest.php`

**Interfaces (used by Tasks 10–13):**
- **`WebRequest`** (final, readonly): `method`, `query`, `post`, `files`, plus `static fromGlobals(): self`.
- **`PageResult`:**
  - Constructors:
    - `static view(array $data = [])`
    - `static redirect(string $location)`: sends `header('Location: ' . $location)`, then exit.
    - `static output(string $body)`: echo, then exit. Used for the `<script>alert(...)</script>` responses.
    - `static halt(string $message)`: `die($message)`.
  - Accessors: `isView(): bool`, `data(): array`, `kind(): string`, `payload(): string`.
  - `sendIfFinal(): void` does nothing for a view and otherwise performs the action and exits. Only `public/` calls it, so handlers stay testable.
- **Handler convention:**
  - `final class XPage { __construct(<services>) ; handle(WebRequest $r, array &$session): PageResult }`.
  - Handlers never echo, call `header()`, exit, or touch superglobals.
  - Services are built in the `public/` file.
- **Entry file shape**, 5–15 lines, keeping the original session line and the original position of the header include relative to redirects:
  ```php
  <?php
  require_once __DIR__ . '/../src/bootstrap.php';
  $result = (new ProductPage(new ProductService($pdo), new ReviewService($pdo)))->handle(WebRequest::fromGlobals(), $_SESSION);
  $result->sendIfFinal();
  extract($result->data());
  include __DIR__ . '/../templates/partials/header.php';
  include __DIR__ . '/../templates/pages/product.php';
  include __DIR__ . '/../templates/partials/footer.php';
  ```
- **`header.php`** replaces its SQL with `(new CartService($pdo))->itemCount(...)`, with the same null/0 handling as today.
- **`product.php` reviews:**
  - It keeps its buggy `r.id = u.id` join (D4) through `ReviewService::forProductLegacyJoin(int $productId): array`.
  - The query lives in `ReviewRepository::forProductLegacyJoin`, with a docblock: `Known bug kept for byte identity (Part B plan D4); fix in follow-up.`
- **The not-found product** renders the same bytes (header, the `<h3>` message, footer), with HTTP 200.

- [ ] **Step 1: Write the failing tests.**
  - `PageResultTest`: kinds and payloads.
  - `ProductPageTest` on SQLite: unknown id → a view with `notFound=true`; a known id → `product`, `sizes`, `reviews` keys.

  Run them; they fail.
- [ ] **Step 2: Implement.** Move the markup byte-for-byte into the templates, including the template-level variables (`$outOfStock`).
- [ ] **Step 3:** Remove `public/index.php`, `public/product.php` and `templates/partials/header.php` from `LEGACY_SQL`. Run the full gate. The snapshot diff must be empty. Commit `refactor(web): handler + template for catalog pages`.

### Task 10: Cart and checkout pages (behaviour preserved)

**Files:**
- Create in `src/app/web/`: `CartPage.php`, `UpdateCartAction.php`, `CheckoutPage.php`, `OrderDetailsPage.php`
- Create in `templates/pages/`: `cart.php`, `checkout.php`, `order_details.php`
- Modify: `public/{cart,update_cart,checkout,order_details}.php`

**Interfaces:**
- `CartPage(CartService)`: remove → `redirect('cart.php')`; guest → `redirect('login.php?error=vui_long_dang_nhap')`.
  - Keep `public/cart.php`'s list query verbatim as `CartRepository::itemsForCartPage` (no ORDER BY), reached through `CartService::itemsForCartPage(int $userId): array`. `CartService::list` orders by `c.id`, which may differ.
  - `ProfilePage` in Task 11 follows the same pattern: `OrderService::listForProfilePage(int $userId, ?string $status): array` over `OrderRepository::listForProfilePage`.
- `UpdateCartAction(CartService)` keeps the GET semantics, unchecked, through `CartService::setQuantityUnchecked` / `setSizeUnchecked`. These wrap the page's verbatim queries, are marked D4, and always `redirect('cart.php')`.
- `CheckoutPage(CartService)`: `public/checkout.php` keeps header-then-guard order (D4).
- `OrderDetailsPage(OrderService)` uses `OrderService::detail()` (D4 exception):
  - not owned or missing → `halt('Đơn hàng không tồn tại hoặc bạn không có quyền xem.')`;
  - the template prints the same markup, with items from `order_items`.

- [ ] **Step 1:** Write `tests/unit/app/web/CartPageTest.php` on SQLite: guest redirect target; remove → redirect; list data keys. Run it; it fails.
- [ ] **Step 2:** Implement. `process_checkout.php` and `add_to_cart.php` are **not** touched here (Task 13).
- [ ] **Step 3:** Run the full gate. The snapshot diff must be empty **except** the `order_details` customer scenario, which goes from 500 to the rendered order. Write that one file's new content into the commit message body. Commit `refactor(web): handler + template for cart and checkout pages`.

### Task 11: Account pages

**Files:**
- Create in `src/app/web/`: `LoginPage.php`, `RegisterPage.php`, `ProfilePage.php`, `SubmitReviewAction.php`
- Create in `templates/pages/`: `login.php`, `register.php`, `profile.php`
- Modify: `public/{login,register,profile,submit_review}.php`

**Interfaces:**
- **`LoginPage(UserService, AppConfig)`:** writes the session through `&$session` with exactly today's keys and values (`user_id`, `username`, `role`, `admin_logged_in`, `api_token`).
  - The env-admin path uses `AppConfig`.
  - The DB-admin path uses `UserService::findStorefrontAdmin(string $login): ?array`.
  - Then `customerByEmail(string)` and `rotateApiToken(int $userId): string`.
  - Error copy is verbatim.
- **`RegisterPage(UserService)`:** `register.php` keeps header-before-redirect (D4). `UserService::emailTaken(string): bool` and `registerFromWeb(string $username, string $email, string $password): bool` hold the verbatim SQL and `rowCount` semantics.
- **`ProfilePage(OrderService)`:** the page's own `SELECT *` list query is kept as `OrderRepository::listForProfilePage` (verbatim). The stats use `completedStats`.
- **`SubmitReviewAction(ReviewService)`:** same validation and redirects.

- [ ] **Step 1:** Write `tests/unit/app/web/LoginPageTest.php` with a fake `AppConfig`: env admin → session keys + redirect `admin/index.php`; locked user → error copy `Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin!`; wrong password → `Tài khoản hoặc mật khẩu không chính xác!`. Use SQLite users fixture DDL inside the test. Run it; it fails.
- [ ] **Step 2:** Implement. Remove `public/login.php` from `LEGACY_ENV`, and these pages from `LEGACY_SQL`.
- [ ] **Step 3:** Run the full gate with an empty snapshot diff. Commit `refactor(web): handler + template for account pages`.

### Task 12: Admin pages

**Files:**
- Create in `src/app/web/admin/`: `AdminLoginPage.php`, `AdminDashboardPage.php`, `AdminOrdersPage.php`, `AdminProductsPage.php`, `AdminAddProductPage.php`, `AdminEditProductPage.php`, `AdminUsersPage.php`, `AdminGuard.php`
- Create in `templates/pages/admin/`: one template per page
- Modify: `public/admin/{index,login,manage_orders,manage_products,add_product,edit_product,manage_users}.php`

**Interfaces:**
- **`AdminGuard::check(array $session, bool $acceptAdminFlag, string $loginUrl): ?PageResult`** reproduces each page's exact condition and target (web-survey Part 1, admin table).
  - `index` and `manage_orders` accept `admin_logged_in` and redirect to `login.php`.
  - `add_product` checks role only and redirects to `login.php`.
  - `edit_product`, `manage_products` and `manage_users` check role only and redirect to `../login.php`.
- **Upload handling** (validation, `uniqid("IMG-", true)`, `move_uploaded_file`, `unlink`) stays in the handlers, with each page's own extension check: strict vs non-strict `in_array`, verbatim (D8). Services receive the final file name.
- Admin pages use the services from Tasks 4–6: `ProductAdminService`, `CategoryService::withSubcategories`, `OrderService::allWithUsername/updateStatus`, `UserService::adminList/setStatus/setRole`, `AdminDashboardService::stats`, `UserService::findAdminForAdminLogin`.
  - Where a page's SQL text differs from the API's (e.g. `manage_products` INSERT columns vs `add_product`), give it its own verbatim repository method and name the page in the docblock.

- [ ] **Step 1:** Write `tests/unit/app/web/admin/AdminGuardTest.php`, one case per page condition. Write `AdminAddProductPageTest`: guest → redirect, with no upload attempted and no insert (the `add_product` exit fix). Run them; they fail.
- [ ] **Step 2:** Implement. Remove `public/admin/login.php` from `LEGACY_ENV` and the admin pages from `LEGACY_SQL`.
- [ ] **Step 3:** Run the full gate with an empty snapshot diff. Commit `refactor(web): handler + template for admin pages`.

### Task 13: Web checkout and add-to-cart use the API logic (the one behaviour change)

**Files:**
- Create in `src/app/web/`: `AddToCartAction.php`, `PlaceOrderAction.php`
- Create: `src/service/order/InsufficientStockException.php`
- Modify:
  - `public/add_to_cart.php`, `public/process_checkout.php`
  - `templates/pages/cart.php` (the Freesize comparison)
  - `src/service/order/OrderService.php`
- Test: `tests/unit/app/web/{AddToCartAction,PlaceOrderAction}Test.php`

**Interfaces:**
- **`InsufficientStockException extends RuntimeException`** with `public readonly int $productId`. The message stays `'Insufficient stock for product <id>'`, so the API and the Task 2 tests are unchanged. `OrderService::create` throws it where it throws `RuntimeException` today.
- **`AddToCartAction(CartService)`:**
  - guest → `output("<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>")`;
  - calls `CartService::add($userId, ['product_id'=>…, 'quantity'=>…, 'size'=>$post['selected_size'] ?? 'S'])`;
  - any `RuntimeException` or `InvalidArgumentException` → `output("<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id=<id>';</script>")`;
  - success → `redirect('checkout.php')` for `buy_now`, otherwise `redirect('cart.php')`;
  - a non-POST request returns an empty view, as today.
- **`PlaceOrderAction(OrderService)`:**
  - guest or non-POST → `redirect('index.php')`;
  - success → `output("<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>")`;
  - `InsufficientStockException` → `halt("Lỗi đặt hàng: Sản phẩm ID {$e->productId} đã hết hàng!")`;
  - `'Cart is empty'` → `halt('Lỗi đặt hàng: Giỏ hàng của bạn đang trống.')` (new copy: the old page created an empty order);
  - any other `Throwable` → `halt('Lỗi đặt hàng: ' . $e->getMessage())`.
- **`templates/pages/cart.php`** compares sizes case-insensitively for the `Freesize` option. For existing rows the output is unchanged.
- **Delete** the repository methods that only the old `process_checkout.php` / `add_to_cart.php` code needed, if Tasks 4–5 created any.

- [ ] **Step 1: Write the failing tests.**
  - `AddToCartActionTest`: guest script; out-of-stock script; merged quantity above stock → the same out-of-stock script; `selected_size=Freesize` stored as `FREESIZE`; no size → `S`; `buy_now` → `checkout.php`.
  - `PlaceOrderActionTest`: success script; insufficient stock → exact halt text and stock unchanged; empty cart → halt text and no order row.

  Run them; they fail.
- [ ] **Step 2: Implement.** `public/process_checkout.php` and `public/add_to_cart.php` become entry files (D10 does not apply: they hold logic).
- [ ] **Step 3: Snapshot.** Run `scripts/dev/web-snapshot.sh current && diff -r reports/web-snapshot/baseline reports/web-snapshot/current`.
  - **Expected diff, limited to:**
    - the scenarios after `process_checkout` whose output shows stock or order status: `pending` → `Chờ xử lý` in profile and admin orders, and lower stock where a page prints it;
    - the add-to-cart scenarios, where size is now stored and stock is capped.
  - Any other diff is a bug.
  - Paste the diff summary (scenario names + one line each) into the commit body. Then re-baseline: `rm -rf reports/web-snapshot/baseline && mv reports/web-snapshot/current reports/web-snapshot/baseline`.
- [ ] **Step 4:** Run the full gate. Commit `fix(web): checkout and add-to-cart use OrderService/CartService (stock lock, size, status)`.

### Task 14: Empty the allowlists and update the docs

**Files:**
- Modify: `tests/unit/ArchitectureTest.php`, `CLAUDE.md`, `.claude/rules/{architecture,testing,database}.md`, `README.md`, `docs/chatbot-spec.md`, `docs/technical-spec.md`, `src/harness/README.md`
- Create: `src/app/README.md`, `src/service/README.md`, `src/repository/README.md` (5–15 lines each: what lives here, may use, how to add one)

- [ ] **Step 1:** Delete `LEGACY_SQL`, `LEGACY_ENV` and `LEGACY_EDGES`, and their skip logic. `testLegacyAllowlistsStillApply` becomes redundant; delete it. Run `scripts/dev/php vendor/bin/phpunit tests/unit/ArchitectureTest.php`. Expected: OK. Any failure is a missed file: fix the code, not the test.
- [ ] **Step 2: Docs.**
  - Layout and layer rules.
  - "Add a page": handler + template + entry file.
  - "Add an endpoint": route + controller + service.
  - "Add a query": repository method.
  - The snapshot harness command in `.claude/rules/testing.md`.
  - The checkout behaviour change in `CLAUDE.md`'s Hard rules context.
  - The D4 follow-up list in `docs/superpowers/specs/2026-10-22-layered-refactor-design.md` §7 step 11, as "known page bugs kept for byte identity".
- [ ] **Step 3: Live check on localhost** (spec §6):
  - `docker compose up -d --build app`, then the migration gate, then `scripts/dev/smoke.sh`, then `node scripts/smoke_chat_stream_latency.mjs`.
  - In a browser session: add a sized product to the cart → checkout. Expect stock reduced by the quantity, and the order visible in `profile.php` and `admin/manage_orders.php` with status `Chờ xử lý`.
  - Record the commands and results in the PR body.
- [ ] **Step 4:** Run the full gate. Commit `docs: website layers, page/endpoint/query how-tos; drop legacy allowlists`.
