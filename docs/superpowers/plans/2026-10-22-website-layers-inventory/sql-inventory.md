> Survey of commit 51625d6 (develop after PR #15). Line numbers refer to that commit and drift as tasks land; file and query text stay valid until the owning task moves them.

# SQL inventory (read-only survey, repo /DATA/home/quyen/Downloads/shop_quan_ao)

Sources: `grep -rnE '\->(prepare|query|exec)\(' public src`. Line numbers are of the statement call (prepare/query/exec). "dup-N" tags mark duplicate groups (see "Duplicates").

## 0. Counts (SQL call sites = occurrences of ->prepare/->query/->exec)

| Area | Files | Call sites |
| --- | --- | --- |
| public/*.php (storefront) | 12 | 34 |
| public/admin/*.php | 7 | 19 |
| **public total** | 19 | **53** |
| src/app/api/controllers/** | 21 | 37 |
| src/app/api/middleware.php | 1 | 1 |
| **api total** | 22 | **38** |
| src/service/** | 7 | **41** (Cart 10, VariantBackfill 8, VariantHydrator 1, Product 9, Knowledge 2, Order 9, Size 2) |
| src/harness/** (memory 21 + middleware 4) | 4 | **25** (ChatbotMemory 9 incl. 2 DDL `exec`, SessionContext 7, ConversationStore 5, FreeShippingNudge 4) |
| **To migrate** | 52 files | **157** |
| src/repository/OutfitCatalogRepository.php (existing) | 1 | 3 (already a repository) |
| Excluded: src/core/database/MigrationRunner.php | 1 | 6 |
| Excluded: src/core/observability/* (LangfuseOtlpTracePublisher 3, LangfuseTraceOutbox 1) | 2 | 4 |
| Grand total in repo | | 170 |

Distinct DB tables touched by to-migrate code: products, categories, product_subcategories, product_sizes, product_variants, colors, reviews, users, cart, orders, order_items, (order_details - see bug note), faqs, size_guides, outfit_suggestions, chat_sessions, chat_messages, chat_session_memory, user_long_term_memory, tool_executions.

Controllers with NO direct SQL (go through services): Cart/*, Orders/*, Knowledge/search, Chatbot/index+stream, Internal/mcp (they use CartService/OrderService/etc.). Only src/harness/tools/*, FreeShippingService, OutfitService have `PDO` ctor args but zero SQL themselves (OutfitService/FreeShippingService delegate to OutfitCatalogRepository).

## 1. How PDO is obtained (exact idioms)

- `config/db.php` (loaded by `src/bootstrap.php` -> `src/core/bootstrap.php` via `require_once`) does `$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", ...)` with `ATTR_ERRMODE = ERRMODE_EXCEPTION`. It defines a plain global `$pdo` (no `Database` class, no `getInstance()` anywhere).
- **Storefront pages / admin pages** (`public/*.php`, `public/admin/*.php`): `require_once __DIR__ . '/../src/bootstrap.php';` (admin: `'/../../src/bootstrap.php'`) then use `$pdo` directly as a script-scope variable.
- **API controllers** (`src/app/api/controllers/**`): included by `public/api/index.php` which already required bootstrap; each controller begins with `global $pdo;` (some also `global $pdo, $route_params;`). Chatbot/index.php, Chatbot/stream.php, Internal/mcp.php use a docblock `/** @var PDO $pdo */` instead (inherit include scope). `src/app/api/middleware.php::authenticate()` uses `global $pdo;`.
- **Services** (`src/service/**`, `ToolApplicationService`, `ToolRegistry`): constructor injection, e.g. `public function __construct(private PDO $pdo)` (CartService, OrderService, SizeService, CatalogVariantHydrator, CatalogVariantBackfill, OutfitService, FreeShippingService); `ProductService(private PDO $pdo, private ?int $userId = null)` also builds `new CatalogVariantHydrator($pdo)` in its ctor; `KnowledgeRetriever(PDO $pdo, ?string $rootDir...)` stores `private PDO $pdo`.
- **Harness**: `ChatbotMemory(PDO $pdo, int $sessionId, ?int $userId = null)`, `PdoChatbotConversationStore(private PDO $pdo, private int $sessionId)`, `FreeShippingNudge(private PDO $pdo, ... int $sessionId)` (built in `AgentFactory::makeMiddleware(string $class, PDO $pdo, int $sessionId)`), and `ChatbotSessionContext::resolve(PDO $pdo, ...)` / `touch(PDO $pdo)` take PDO as a method argument (static factory, not ctor).
- **Existing repository pattern** (`src/repository/OutfitCatalogRepository.php`): `final class OutfitCatalogRepository { public function __construct(private PDO $pdo) {} ... }`, no namespace, classmap autoload, plain `$this->pdo->query()/prepare()` with SQL as single-quoted/multi-line string literals, returns typed domain objects / arrays with docblock `@return list<...>`, `PDOException` swallowed only where a table is optional (`product_variants`). Test: `tests/unit/repository/OutfitCatalogRepositoryTest.php` (adds `product_variants` itself on top of SqliteShop). Follow this: `final class XRepository { public function __construct(private PDO $pdo) {} }`, pages/controllers do `$x = new XRepository($pdo);`.
- After adding classes: `scripts/dev/composer dump-autoload` (classmap).

## 2. Transactions / locking (who owns them)

| Owner | Function | Detail |
| --- | --- | --- |
| `src/service/order/OrderService.php` | `create()` lines 76-117 | `beginTransaction()` (l.78), `FOR UPDATE` appended to cart+products select only when driver is mysql (l.80-83, `$lockClause`), `commit()` l.110, `rollBack()` l.113-115 under `inTransaction()`. Inside: INSERT orders, INSERT order_items (prepared once, loop), `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?` (loop, rowCount()!==1 throws), DELETE cart. Order status literal here is `'Chờ xử lý'`. |
| `src/service/cart/CartService.php` | `add()` lines 30-82 | Starts a transaction only if `!$pdo->inTransaction()` (l.40-43, flag `$transactionStarted`), commit l.72 / rollBack l.76 conditional on the flag. No FOR UPDATE. Calls: select product stock, select cart row, update cart OR insert cart. |
| `public/process_checkout.php` | top-level script, lines 17-65 | `$pdo->beginTransaction()` l.17, `commit()` l.61, `rollBack()` l.65 in catch. Legacy page duplicating OrderService::create (no FOR UPDATE, no stock decrement, status literal `'pending'`, uses `NOW()`). 7 SQL calls inside one try. |
| nobody else | | MigrationRunner is excluded. |

Design implication: the transaction boundary should stay in the service (`OrderService::create`, `CartService::add`) calling `$pdo->beginTransaction()`; repositories share the same PDO instance, so repository methods called inside are automatically in the txn. For `public/process_checkout.php`, either keep the txn in the page and call repository methods, or (better) replace it by `OrderService::create`; but behavior differs (status literal, stock decrement) - flag to planner. `OrderRepository` needs a `lockClause` variant (`cartLinesWithStock(int $userId, bool $forUpdate)`), because the txn driver check uses `getAttribute(PDO::ATTR_DRIVER_NAME)`; keep that check in the repository method or pass `$forUpdate` from the service.

## 3. Dynamic SQL (builders)

| File:line | What is dynamic | Notes |
| --- | --- | --- |
| `public/index.php` 14-24 | `SELECT * FROM products WHERE name LIKE ?` + optional ` AND category_id = ?` | search + category filter |
| `public/profile.php` 17-30 | `SELECT * FROM orders WHERE user_id = ?` + optional ` AND status = ?` + ` ORDER BY created_at DESC` | near-dup of OrderService::list (different column list: `*` vs `id,total_price,status,created_at`) |
| `src/app/api/controllers/Faq/index.php` 9-26 | `SELECT * FROM faqs WHERE 1=1` + optional ` AND category = ?` (whitelist) + ` AND (question LIKE ? OR answer LIKE ?)` + ` ORDER BY priority, id` | |
| `src/app/api/controllers/Outfit/index.php` 34-59 | `SELECT o.*, p1.name as product_name ... FROM outfit_suggestions o JOIN products p1 ... JOIN products p2 ... WHERE 1=1` + ` AND (o.product_id = ? OR o.paired_product_id = ?)` + ` AND (p1.name LIKE ? OR p2.name LIKE ?)` + ` ORDER BY o.id` | uses `outfit_suggestions` table (legacy, NOT the UC-A OutfitBuilder). Table is not in SqliteShop |
| `src/app/api/controllers/Products/list.php` 104-214 | **Biggest builder.** `$where[]` pieces: FULLTEXT `MATCH(p.name) AGAINST(? IN BOOLEAN MODE)`, `p.name LIKE ?`, token ANDs, `p.category_id = ?` / `c.canonical_key = ?`, `sc.canonical_key = ?`, `p.price >=/<= ?`, in_stock EXISTS on product_variants, color EXISTS on product_variants+colors. Then COUNT query (l.185-190, `$sqlCount`) and data query (l.201-214, `$sqlData`) with whitelisted `ORDER BY` (switch) and interpolated `LIMIT $limit OFFSET $offset` (ints). 2 prepares. | search-term parsing (clean, words, fts query) is PHP logic mixed into the SQL builder; repository should take already-normalised filters: `ProductRepository::searchPage(array $filters, string $sort, ?int $limit, int $offset): array{rows,total}` and move parsing with it (or keep parsing in controller and pass `$where`/`$params`? NO - prefer filter DTO array) |
| `src/service/catalog/ProductService.php::executeSearchProductsDirect` 203-309 | `SELECT p.id,... ,p.description,... WHERE 1=1` + category_id, subcategory, min/max price, in_stock EXISTS, size (EXISTS variants OR product_sizes), color (EXISTS OR NOT EXISTS variants) ; `ORDER BY p.price ASC` (l.263); executed at l.264 and **executed again with the identical `$sql/$params` at l.275** inside the "all words" fallback (same query re-run; repository should return rows once and let the caller reuse them, but SQL text unchanged) | PHP post-filters on name in memory |
| `ProductService` 311-322, 394 | `IN ($placeholders)` built with `array_fill` | placeholders for ids |
| `CatalogVariantHydrator` 79-89 | `IN ($placeholders)` | |
| `public/admin/*` / `Admin/product_update.php` 226-245 | `UPDATE products SET <fields built from whitelist of columns> WHERE id = ?`; fields: name, price, stock, description, category_id, subcategory_id, image, style_tags | whitelist is hard-coded in controller foreach; repository `ProductRepository::updateFields(int $id, array $fields): int` must keep column whitelist inside the repository |
| `OrderService::list` 28-39 | `SELECT id, total_price, status, created_at FROM orders WHERE user_id = ?` + optional ` AND status = ?` + ` ORDER BY created_at DESC` | |
| `OrderService::create` 80-83 | `" FOR UPDATE"` appended only on mysql | |
| `SizeService::suggestSizeDirect` 32-47 | `$orderBy` variable (sqlite vs mysql: `FIELD()` not on sqlite) with `WHERE category_id = ?` or without | two statements: prepare vs query; `isSqlite()` branch (SizeService l.87) |
| `PdoChatbotConversationStore` 77-79, 93-97 | `sqlNow()` -> `CURRENT_TIMESTAMP` (sqlite) / `NOW()` | |
| `ChatbotMemory` 314-325, 343-360 | sqlite `ON CONFLICT ... DO UPDATE` vs mysql `ON DUPLICATE KEY UPDATE ... NOW()` | driver-branch inside the same method |
| `CartService::update` 100 | `'SELECT stock FROM products WHERE id = ' . (int) $item['product_id']` (concatenation of cast int, not prepared) | keep verbatim or make `?`; planner decision |
| `ChatbotSessionContext::touch` / `ConversationStore` 77 | `'UPDATE chat_sessions SET updated_at = ' . $this->sqlNow() . ' WHERE id = ?'` vs `ChatbotSessionContext::touch` which uses literal `NOW()` | two variants |

## 4. Per-file tables

Legend: proposed = `Repository::method(signature)`. Params are positional `?` unless noted.

### 4.1 public/*.php (storefront)

| file:line | op | tables | purpose | params | proposed |
| --- | --- | --- | --- | --- | --- |
| public/add_to_cart.php:20 | SELECT | products | stock of product | product_id | `ProductRepository::stockById(int $id): ?int` [dup-STOCK] |
| public/add_to_cart.php:29 | SELECT | cart | existing cart line for user+product | user_id, product_id | `CartRepository::findLine(int $userId, int $productId): ?array` [dup-CARTLINE] (id, quantity) |
| public/add_to_cart.php:35 | UPDATE | cart | set quantity by cart id | quantity, cart_id | `CartRepository::setQuantity(int $cartId, int $quantity): void` [dup-CARTQTY] (CartService:104 identical SQL `UPDATE cart SET quantity = ? WHERE id = ?`) |
| public/add_to_cart.php:38 | INSERT | cart | add new line without size (3 cols) | user_id, product_id, quantity | `CartRepository::insertLine(int $userId, int $productId, int $quantity): int` (variant without size; CartService:67 inserts 4 cols incl. size -> separate method `insertLineWithSize`) |
| public/cart.php:18 | DELETE | cart | remove one line | cart_id, user_id | `CartRepository::deleteLine(int $cartId, int $userId): int` [dup-CARTDEL] (CartService:118 identical) |
| public/cart.php:25 | SELECT | cart, products | cart lines with product info (no ORDER BY) | user_id | `CartRepository::itemsWithProduct(int $userId): array` [near-dup of CartService:11 which adds `ORDER BY c.id`, and `c.product_id` vs `p.id as product_id`] |
| public/checkout.php:13 | SELECT | cart, products | checkout summary lines | user_id | `CartRepository::checkoutSummary(int $userId): array` |
| public/index.php:10 | SELECT | categories | all categories | - | `CategoryRepository::all(): array` (`SELECT * FROM categories`; dup-ish of Categories/list.php with ORDER BY id) |
| public/index.php:22 | SELECT | products | search + category filter, dynamic | `%$search%`, [category_id] | `ProductRepository::searchByNameAndCategory(string $search, $categoryId): array` |
| public/login.php:22 | SELECT | users | admin login by email/username | email, email | `UserRepository::findAdminByLogin(string $login): ?array` [dup-ADMINLOGIN: public/admin/login.php:30 has same shape with column order swapped `(username = ? OR email = ?)`] |
| public/login.php:39 | SELECT | users | user by email | email | `UserRepository::findByEmail(string $email): ?array` (`SELECT * FROM users WHERE email = ?`) |
| public/login.php:55 | UPDATE | users | set api_token | token, id | `UserRepository::setApiToken(int $id, string $token): void` [dup-SETTOKEN: Auth/login.php:32] |
| public/order_details.php:14 | SELECT | orders | order owned by user (`*`) | order_id, user_id | `OrderRepository::findForUser(int $orderId, int $userId): ?array` (`SELECT *`; OrderService:56 selects 4 columns - different) |
| public/order_details.php:21 | SELECT | order_details (!), products | order lines | order_id | `OrderRepository::itemsForOrder(int $orderId): array` - **BUG: table `order_details` does not exist in schema.sql nor migrations; only `order_items` exists. Page is broken today. Do not change SQL verbatim without deciding; OrderService:63 has the correct query over order_items** |
| public/process_checkout.php:20 | SELECT | cart | cart product_id, quantity | user_id | `CartRepository::linesByUser(int $userId): array` |
| public/process_checkout.php:25 | SELECT | products | stock | product_id | `ProductRepository::stockById` [dup-STOCK] (loop, N+1) |
| public/process_checkout.php:36 | SELECT | products | price | product_id | `ProductRepository::priceById(int $id): float` [dup-PRICE with :49] |
| public/process_checkout.php:43 | INSERT | orders | create order (status 'pending', NOW()) | user_id, total | `OrderRepository::insertOrderPending(int $userId, float $total): int` (differs from OrderService:96 status literal 'Chờ xử lý' + CURRENT_TIMESTAMP) |
| public/process_checkout.php:49 | SELECT | products | price | product_id | `ProductRepository::priceById` [dup-PRICE] |
| public/process_checkout.php:53 | INSERT | order_items | order line | order_id, product_id, qty, price | `OrderRepository::insertItem(int $orderId, int $productId, int $quantity, float $price): void` [dup-ORDERITEM: OrderService:99 identical] |
| public/process_checkout.php:58 | DELETE | cart | clear cart | user_id | `CartRepository::clear(int $userId): void` [dup-CARTCLEAR: OrderService:109 identical] |
| public/product.php:10 | SELECT | products | product `*` by id | id | `ProductRepository::findById(int $id): ?array` [dup-PRODBYID: admin/edit_product.php:9 identical `SELECT * FROM products WHERE id = ?`] |
| public/product.php:21 | SELECT | product_sizes | sizes `*` of product | product_id | `SizeRepository::sizesForProduct(int $productId): array` [dup-SIZES: Products/detail.php:25, Products/sizes.php:8, ProductService:368 all identical `SELECT * FROM product_sizes WHERE product_id = ?`] |
| public/product.php:26 | SELECT | reviews, users | reviews of product | product_id | `ReviewRepository::forProduct(int $productId): array` - **bug: joins `r.id = u.id` (should be `r.user_id`)**; API Products/reviews.php:8 uses `r.user_id = u.id`. SQL differs; keep both until decision |
| public/profile.php:28 | SELECT | orders | user's orders, optional status filter (dynamic) | user_id, [status] | `OrderRepository::listForUser(int $userId, ?string $status): array` (`SELECT *` variant) |
| public/profile.php:33 | SELECT | orders | completed stats SUM/COUNT | user_id | `OrderRepository::completedStats(int $userId): array` [dup-STATS: OrderService:40 identical text modulo whitespace/aliases `AS`] |
| public/register.php:18 | SELECT | users | email exists (uses rowCount) | email | `UserRepository::emailExists(string $email): bool` [dup-EMAILEXISTS: Auth/register.php:24 uses `SELECT id` + fetch] |
| public/register.php:25 | INSERT | users | new user (username,email,password,api_token) | 4 | `UserRepository::insert(...)` page variant (no role/status columns - defaults) vs Auth/register.php:33 variant (`role 'user', status 1` literals) -> `UserRepository::create(string $username, string $email, string $hash, string $token, bool $explicitRoleStatus)` or two methods |
| public/submit_review.php:15 | INSERT | reviews | add review | product_id, user_id, rating, comment | `ReviewRepository::add(int $productId, int $userId, int $rating, string $comment): void` [dup-REVIEWADD: Products/add_review.php:21 identical] |
| public/update_cart.php:12 | UPDATE | cart | set quantity (scoped to user) | qty, cart_id, user_id | `CartRepository::setQuantityForUser(int $cartId, int $userId, int $quantity)` (`... AND user_id = ?` - differs from CartService `WHERE id = ?`) |
| public/update_cart.php:14 | UPDATE | cart | set size (scoped to user) | size, cart_id, user_id | `CartRepository::setSizeForUser(int $cartId, int $userId, string $size)` |

### 4.2 public/admin/*.php

| file:line | op | tables | purpose | params | proposed |
| --- | --- | --- | --- | --- | --- |
| admin/add_product.php:32 | INSERT | products | add product (name,price,category_id,image,style_tags) | 5 | `ProductRepository::insertBasic(...)` (5 cols) - differs from manage_products:43 & product_create (8 cols) |
| admin/edit_product.php:9 | SELECT | products | product by id | id | `ProductRepository::findById` [dup-PRODBYID] |
| admin/edit_product.php:14 | SELECT | categories | id,name ordered | - | `CategoryRepository::idNameList(): array` [dup-CATLIST: manage_products.php:11] |
| admin/edit_product.php:15 | SELECT | product_subcategories, categories | subcategory list join | - | `CategoryRepository::subcategoriesWithCategory(): array` [dup-SUBCATS: manage_products.php:12 identical] |
| admin/edit_product.php:52 | UPDATE | products | update all 8 editable cols | 9 | `ProductRepository::updateAll(int $id, array $fields): bool` (fixed full-column UPDATE; Admin/product_update.php is the partial/dynamic variant) |
| admin/index.php:14 | SELECT | products | COUNT(*) | - | `ProductRepository::count(): int` [dup-COUNT: Admin/dashboard.php:7] |
| admin/index.php:17 | SELECT | orders | COUNT(*) | - | `OrderRepository::count(): int` [dup: dashboard.php:8] |
| admin/index.php:20 | SELECT | users | COUNT(*) | - | `UserRepository::count(): int` [dup: dashboard.php:9] |
| admin/index.php:24 | SELECT | orders | SUM total_price status 'Đã hoàn thành' | - | `OrderRepository::completedRevenue(): float` [dup: dashboard.php:11] |
| admin/login.php:30 | SELECT | users | admin by username/email | username, username | `UserRepository::findAdminByLogin` [dup-ADMINLOGIN] |
| admin/manage_orders.php:17 | UPDATE | orders | set status | status, id | `OrderRepository::updateStatus(int $id, string $status): int` [dup-ORDERSTATUS: Admin/order_status.php:17] |
| admin/manage_orders.php:24 | SELECT | orders, users | all orders with username | - | `OrderRepository::allWithUsername(): array` [dup-ADMINORDERS: Admin/orders.php:6] |
| admin/manage_products.php:11 | SELECT | categories | id,name | - | `CategoryRepository::idNameList` [dup-CATLIST] |
| admin/manage_products.php:12 | SELECT | product_subcategories, categories | subcats join | - | `CategoryRepository::subcategoriesWithCategory` [dup-SUBCATS] |
| admin/manage_products.php:43 | INSERT | products | add (8 cols) | 8 | `ProductRepository::insert(array $data): int` [dup-PRODINSERT: Admin/product_create.php:19 identical text] |
| admin/manage_products.php:69 | SELECT | products | image by id | id | `ProductRepository::imageById(int $id): ?string` [dup-IMG: Admin/product_delete.php:10] |
| admin/manage_products.php:77 | DELETE | products | delete product | id | `ProductRepository::delete(int $id): int` [dup-PRODDEL: Admin/product_delete.php:18] |
| admin/manage_products.php:83 | SELECT | products | all `ORDER BY id DESC` | - | `ProductRepository::allNewestFirst(): array` [dup-ADMINPRODS: Admin/products.php:6] |
| admin/manage_users.php:22 | UPDATE | users | lock (status=0) | id | `UserRepository::setStatus(int $id, int $status): int` [dup: Admin/user_action.php:16 (0), :22 (1); here also :26 (1)] - literal 0/1 in SQL; one method with param OR two (`lock`/`unlock`) to keep text verbatim |
| admin/manage_users.php:26 | UPDATE | users | unlock (status=1) | id | same as above |
| admin/manage_users.php:42 | UPDATE | users | set role | role, id | `UserRepository::setRole(int $id, string $role): int` [dup: Admin/user_action.php:31] |
| admin/manage_users.php:49 | SELECT | users | list columns (id,username,email,role,status,created_at) | - | `UserRepository::listForAdmin(): array` [dup: Admin/users.php:6] |

### 4.3 src/app/api (controllers + middleware)

| file:line | op | tables | purpose | params | proposed |
| --- | --- | --- | --- | --- | --- |
| middleware.php:15 | SELECT | users | auth by api_token (id,username,email,role,status) | token | `UserRepository::findByApiToken(string $token): ?array` [near-dup: ChatbotSessionContext:21 selects only `id`] |
| Admin/dashboard.php:7 | SELECT | products | COUNT | - | `ProductRepository::count` [dup] |
| Admin/dashboard.php:8 | SELECT | orders | COUNT | - | `OrderRepository::count` [dup] |
| Admin/dashboard.php:9 | SELECT | users | COUNT | - | `UserRepository::count` [dup] |
| Admin/dashboard.php:11 | SELECT | orders | revenue | - | `OrderRepository::completedRevenue` [dup] |
| Admin/orders.php:6 | SELECT | orders, users | all orders + username | - | `OrderRepository::allWithUsername` [dup] |
| Admin/order_status.php:17 | UPDATE | orders | set status (uses rowCount) | status, id | `OrderRepository::updateStatus` returns affected rows [dup] |
| Admin/product_create.php:19 | INSERT | products | add 8 cols (+ lastInsertId) | 8 | `ProductRepository::insert` returns new id [dup] |
| Admin/product_delete.php:10 | SELECT | products | image | id | `ProductRepository::imageById` [dup] |
| Admin/product_delete.php:18 | DELETE | products | delete (rowCount) | id | `ProductRepository::delete` [dup] |
| Admin/products.php:6 | SELECT | products | all newest first | - | `ProductRepository::allNewestFirst` [dup] |
| Admin/product_update.php:30 | UPDATE | products | **dynamic** partial update | values..., id | `ProductRepository::updatePartial(int $id, array $fields): int` |
| Admin/product_update.php:35 | SELECT | products | existence check `SELECT id` | id | `ProductRepository::exists(int $id): bool` [dup-EXISTS: Products/add_review.php:17] |
| Admin/user_action.php:16 | UPDATE | users | lock | id | `UserRepository::setStatus`/`lock` [dup] |
| Admin/user_action.php:22 | UPDATE | users | unlock | id | `UserRepository::unlock` [dup] |
| Admin/user_action.php:31 | UPDATE | users | set role | role, id | `UserRepository::setRole` [dup] |
| Admin/users.php:6 | SELECT | users | list | - | `UserRepository::listForAdmin` [dup] |
| Auth/login.php:18 | SELECT | users | by email OR username `LIMIT 1` | email, email | `UserRepository::findByEmailOrUsername(string $login): ?array` |
| Auth/login.php:32 | UPDATE | users | set api_token | token, id | `UserRepository::setApiToken` [dup] |
| Auth/logout.php:7 | UPDATE | users | api_token = NULL | id | `UserRepository::clearApiToken(int $id): void` |
| Auth/register.php:24 | SELECT | users | email exists | email | `UserRepository::emailExists` [dup] |
| Auth/register.php:33 | INSERT | users | new user with role 'user', status 1 | username,email,hash,token | `UserRepository::create(...)` [near-dup of register.php:25] |
| Categories/list.php:4 | SELECT | categories | `SELECT * ... ORDER BY id` | - | `CategoryRepository::allOrdered(): array` (near-dup of index.php:10 without ORDER BY) |
| Chatbot/history.php:14 | SELECT | chat_sessions | latest active session of user | user_id | `ChatSessionRepository::latestActiveForUser(int $userId): ?array` (multi-line text with `id, session_token`; ChatbotSessionContext:28 is the same query in single-line form) [dup-LATESTSESSION] |
| Chatbot/history.php:30 | SELECT | chat_messages | messages of session ASC | session_id | `ChatConversationRepository::messagesForSession(int $sessionId): array` |
| Faq/index.php:24 | SELECT | faqs | dynamic filters | [category], [q,q] | `FaqRepository::search(?string $category, string $search): array` |
| Outfit/index.php:31 | SELECT | outfit_suggestions, products | dynamic | [pid,pid], [q,q] | `OutfitCatalogRepository::suggestions(?int $productId, string $search): array` (or `OutfitSuggestionRepository`; existing OutfitCatalogRepository reads products only) |
| Products/add_review.php:17 | SELECT | products | exists | id | `ProductRepository::exists` [dup] |
| Products/add_review.php:21 | INSERT | reviews | add review | 4 | `ReviewRepository::add` [dup] |
| Products/detail.php:8 | SELECT | products, categories, product_subcategories | detail with joins (p.*, c.name as category_name...) | id | `ProductRepository::findWithTaxonomy(int $id): ?array` (no `SELECT p.*` match in ProductService:353 - different column list) |
| Products/detail.php:25 | SELECT | product_sizes | sizes | id | `SizeRepository::sizesForProduct` [dup] |
| Products/detail.php:32 | SELECT | reviews | AVG(rating), COUNT | id | `ReviewRepository::ratingSummary(int $productId): array` [dup-RATING: ProductService:375 identical] |
| Products/list.php:114 | SELECT | products,categories,product_subcategories | **dynamic** count | params | `ProductRepository::searchCount(array $filters): int` |
| Products/list.php:139 | SELECT | same | **dynamic** page | params | `ProductRepository::searchPage(array $filters, string $sort, ?int $limit, int $offset): array` |
| Products/reviews.php:8 | SELECT | reviews, users | reviews (join `r.user_id = u.id`) | id | `ReviewRepository::forProductWithUsername` [differs from public/product.php:26] |
| Products/sizes.php:8 | SELECT | product_sizes | sizes | id | `SizeRepository::sizesForProduct` [dup] |
| SizeGuide/index.php:14 | SELECT | size_guides | by category ordered FIELD | category_id | `SizeRepository::guidesByCategory(int $categoryId): array` [near-dup of SizeService:42, which interpolates `$orderBy`] |
| SizeGuide/index.php:17 | SELECT | size_guides | all ordered FIELD | - | `SizeRepository::allGuides(): array` [near-dup of SizeService:45] |

### 4.4 src/service/**

| file:line | op | tables | purpose | params | proposed |
| --- | --- | --- | --- | --- | --- |
| cart/CartService.php:11 | SELECT | cart, products | cart list with subtotal `ORDER BY c.id` (list()) | user_id | `CartRepository::itemsWithProduct(int $userId)` [dup-CARTITEMS; near-dup of public/cart.php:25 (ORDER BY + column alias differ) and public/checkout.php:13] |
| cart/CartService.php:45 | SELECT | products | `id, stock` of product (inside txn) | id | `ProductRepository::idAndStock(int $id): ?array` |
| cart/CartService.php:55 | SELECT | cart | `id, quantity` existing line | user_id, product_id | `CartRepository::findLine` [dup-CARTLINE: add_to_cart.php:29 identical] |
| cart/CartService.php:63 | UPDATE | cart | quantity + size | qty,size,id | `CartRepository::updateQuantityAndSize(int $id, int $qty, string $size)` |
| cart/CartService.php:67 | INSERT | cart | new line with size | user_id, product_id, qty, size | `CartRepository::insertLineWithSize(...)`, returns lastInsertId |
| cart/CartService.php:89 | SELECT | cart | `id, product_id` ownership check | cart_id, user_id | `CartRepository::findOwnedLine(int $cartId, int $userId): ?array` |
| cart/CartService.php:100 | SELECT | products | stock (concatenated int) | product_id | `ProductRepository::stockById` [dup-STOCK; `->query()` with concatenated id] |
| cart/CartService.php:104 | UPDATE | cart | qty | qty,id | `CartRepository::setQuantity` [dup] |
| cart/CartService.php:111 | UPDATE | cart | size | size,id | `CartRepository::setSize(int $cartId, string $size)` |
| cart/CartService.php:118 | DELETE | cart | remove (rowCount) | id,user_id | `CartRepository::deleteLine` [dup] |
| catalog/CatalogVariantBackfill.php:13 | SELECT | products | id,name,description all | - | `ProductRepository::allForBackfill(): array` |
| catalog/CatalogVariantBackfill.php:56 | INSERT | product_variants | create legacy variant | 4 | `VariantRepository::insertLegacy(int $productId, string $key, ?int $colorId, ?string $size)` |
| catalog/CatalogVariantBackfill.php:71 | SELECT | product_sizes | product_id,size_name ordered | - | `SizeRepository::allProductSizes(): array` (OutfitCatalogRepository::sizesByProduct l.~46 has near-dup without ORDER BY) [dup-ALLSIZES] |
| catalog/CatalogVariantBackfill.php:85 | SELECT | colors | id, canonical_key | - | `VariantRepository::colorKeyMap(): array` (table `colors`) |
| catalog/CatalogVariantBackfill.php:93 | SELECT | product_variants | variant exists | product_id, key | `VariantRepository::exists(int $productId, string $key): bool` |
| catalog/CatalogVariantBackfill.php:100 | SELECT | product_variants | keys of product | product_id | `VariantRepository::keysForProduct(int $productId): array` |
| catalog/CatalogVariantBackfill.php:112 | SELECT | product_variants | legacy keys `LIKE 'legacy|%'` | product_id,'legacy|%' | `VariantRepository::legacyRows(int $productId): array` |
| catalog/CatalogVariantBackfill.php:118 | UPDATE | product_variants | rekey + color (inside loop) | key,color,id | `VariantRepository::rekey(int $id, string $key, int $colorId)` |
| catalog/CatalogVariantHydrator.php:81 | SELECT | product_variants, colors | active variants `IN (...)` (dynamic placeholders; wrapped in try/catch) | ids | `VariantRepository::activeByProductIds(array $ids): array` |
| catalog/ProductService.php:118 | SELECT | categories | id,name,canonical_key,family | - | `CategoryRepository::taxonomyList(): array` |
| catalog/ProductService.php:264 | SELECT | products,categories,product_subcategories,(variants,product_sizes,colors) | **dynamic** search (see 3) | params | `ProductRepository::searchForAgent(array $args): array` |
| catalog/ProductService.php:275 | SELECT | same | **same $sql/$params re-run** | params | same method (call once; return rows) |
| catalog/ProductService.php:315 | SELECT | products,categories,product_subcategories | cards for ids `IN (...)` | ids | `ProductRepository::cardsByIds(array $ids): array` |
| catalog/ProductService.php:341 | SELECT | products | id, price, stock | id | `ProductRepository::priceAndStock(int $id): ?array` |
| catalog/ProductService.php:353 | SELECT | products,categories,product_subcategories | detail w/ taxonomy (different column list vs Products/detail.php:8) | id | `ProductRepository::detailStatic(int $id): ?array` |
| catalog/ProductService.php:368 | SELECT | product_sizes | sizes (in try/catch) | id | `SizeRepository::sizesForProduct` [dup] |
| catalog/ProductService.php:375 | SELECT | reviews | rating summary (try/catch) | id | `ReviewRepository::ratingSummary` [dup] |
| catalog/ProductService.php:394 | SELECT | product_sizes | sizes for ids `IN (...)` (try/catch) | ids | `SizeRepository::sizesForProducts(array $ids): array` |
| knowledge/KnowledgeRetriever.php:483 | SELECT | faqs | question,answer,category all (try/catch) | - | `FaqRepository::allForKnowledge(): array` |
| knowledge/KnowledgeRetriever.php:496 | SELECT | size_guides | all columns for docs (try/catch) | - | `SizeRepository::allForKnowledge(): array` |
| order/OrderService.php:37 | SELECT | orders | **dynamic** list (id,total_price,status,created_at) | user_id,[status] | `OrderRepository::listForUser(int $userId, ?string $status)` (column list differs from public/profile.php `SELECT *`) |
| order/OrderService.php:40 | SELECT | orders | stats | user_id | `OrderRepository::completedStats` [dup] |
| order/OrderService.php:56 | SELECT | orders | one order of user (4 cols) | id,user_id | `OrderRepository::findForUser` (column list differs from order_details.php) |
| order/OrderService.php:63 | SELECT | order_items, products | order lines | order_id | `OrderRepository::itemsForOrder(int $orderId)` |
| order/OrderService.php:81 | SELECT (FOR UPDATE on mysql) | cart, products | cart lines with price+stock inside txn | user_id | `OrderRepository::cartLinesWithStockForUpdate(int $userId): array` (ptional driver clause) |
| order/OrderService.php:96 | INSERT | orders | status 'Chờ xử lý' + CURRENT_TIMESTAMP | user_id,total | `OrderRepository::insertOrder(int $userId, float $total): int` |
| order/OrderService.php:99 | INSERT | order_items | line | 4 | `OrderRepository::insertItem` [dup] |
| order/OrderService.php:100 | UPDATE | products | `stock = stock - ? WHERE id = ? AND stock >= ?` | qty,id,qty | `ProductRepository::decrementStock(int $id, int $qty): bool` (rowCount()===1) |
| order/OrderService.php:109 | DELETE | cart | clear | user_id | `CartRepository::clear` [dup] |
| size/SizeService.php:42 | SELECT | size_guides | by category + `$orderBy` | category_id | `SizeRepository::guidesByCategory(int $categoryId, bool $sqlite)` [dup-ish SizeGuide/index.php:14; sqlite driver branch for FIELD()] |
| size/SizeService.php:45 | SELECT | size_guides | all + `$orderBy` | - | `SizeRepository::allGuides(bool $sqlite)` |

### 4.5 src/harness/** (memory, middleware)

| file:line | op | tables | purpose | params | proposed |
| --- | --- | --- | --- | --- | --- |
| memory/ChatbotMemory.php:96 | DDL (exec) | chat_session_memory | CREATE TABLE IF NOT EXISTS (runtime DDL, even though migrations exist) | - | `ChatMemoryRepository::ensureSessionMemoryTable(): void` (or delete since schema.sql has it; planner decision) |
| memory/ChatbotMemory.php:106 | DDL (exec) | user_long_term_memory | CREATE TABLE IF NOT EXISTS | - | `ChatMemoryRepository::ensureLongTermTable(): void` |
| memory/ChatbotMemory.php:151 | SELECT | chat_session_memory | summary, slots | session_id | `ChatMemoryRepository::sessionMemory(int $sessionId): ?array` |
| memory/ChatbotMemory.php:293 | SELECT | user_long_term_memory | 5 json columns | user_id | `ChatMemoryRepository::longTerm(int $userId): ?array` |
| memory/ChatbotMemory.php:315 | INSERT upsert | chat_session_memory | sqlite `ON CONFLICT` | session_id,summary,slots | `ChatMemoryRepository::saveSession(int $sessionId, string $summary, string $slotsJson): void` - **driver branch**: the mysql version is l.322 `ON DUPLICATE KEY UPDATE`; one method containing both SQLs with `driverName()` |
| memory/ChatbotMemory.php:322 | INSERT upsert | chat_session_memory | mysql variant | same | same method |
| memory/ChatbotMemory.php:344 | INSERT upsert | user_long_term_memory | sqlite `ON CONFLICT` | user_id, 5 json | `ChatMemoryRepository::saveLongTerm(int $userId, array $payload): void` |
| memory/ChatbotMemory.php:354 | INSERT upsert | user_long_term_memory | mysql variant | same | same method |
| memory/ChatbotMemory.php:397 | SELECT | orders, order_items, products | last 10 purchases (date, product) in try/catch | user_id | `OrderRepository::recentPurchases(int $userId, int $limit = 10)` (LIMIT literal 10 in SQL) |
| memory/ChatbotSessionContext.php:21 | SELECT | users | id by api_token | token | `UserRepository::idByApiToken(string $token): ?int` [near-dup of middleware.php:15] |
| memory/ChatbotSessionContext.php:28 | SELECT | chat_sessions | latest active session of user | user_id | `ChatSessionRepository::latestActiveForUser` [dup-LATESTSESSION with Chatbot/history.php:14] |
| memory/ChatbotSessionContext.php:36 | INSERT | chat_sessions | new session for user | user_id, token | `ChatSessionRepository::create(?int $userId, string $token): int` |
| memory/ChatbotSessionContext.php:42 | SELECT | chat_sessions | id by token & active | token | `ChatSessionRepository::findActiveByToken(string $token): ?int` |
| memory/ChatbotSessionContext.php:51 | INSERT | chat_sessions | anonymous session (`user_id` NULL literal) | token | `ChatSessionRepository::create(null, $token)` (SQL text differs: `VALUES (NULL, ?)`) |
| memory/ChatbotSessionContext.php:59 | UPDATE | chat_sessions | attach user if NULL | user_id, id | `ChatSessionRepository::attachUser(int $id, int $userId): void` |
| memory/ChatbotSessionContext.php:62 | UPDATE | chat_sessions | touch `updated_at = NOW(), user_id = COALESCE(?, user_id)` | user_id, id | `ChatSessionRepository::touch(int $id, ?int $userId): void` (NOW() hard-coded: mysql-only, differs from ConversationStore `sqlNow()`) |
| memory/PdoChatbotConversationStore.php:15 | SELECT | chat_messages | last 8 bot metadata | session_id | `ChatConversationRepository::recentBotMetadata(int $sessionId, int $limit = 8)` (LIMIT literal) |
| memory/PdoChatbotConversationStore.php:46 | INSERT | chat_messages | user message (`'user'` literal) | session, message, metadata | `ChatConversationRepository::addMessage(int $sessionId, string $role, string $message, ?string $metadata)` - **role is a literal inside SQL** ('user' vs 'bot' at :69): two methods `addUserMessage`/`addBotMessage` to keep verbatim |
| memory/PdoChatbotConversationStore.php:69 | INSERT | chat_messages | bot message | same | same |
| memory/PdoChatbotConversationStore.php:77 | UPDATE | chat_sessions | `updated_at = <sqlNow>` | id | `ChatSessionRepository::touchUpdatedAt(int $id)` (sqlNow() helper moves to repository) |
| memory/PdoChatbotConversationStore.php:93 | INSERT | tool_executions | log tool run (sqlNow) | session,tool,args,result,duration,success | `ChatConversationRepository::logToolExecution(...)` (or `ToolExecutionRepository`) |
| middleware/FreeShippingNudge.php:98 | SELECT | chat_session_memory | slots (existence check) | session_id | `ChatMemoryRepository::slotsJson(int $sessionId): ?string` [dup-SLOTS with :114] |
| middleware/FreeShippingNudge.php:105 | UPDATE | chat_session_memory | `SET slots = ? WHERE session_id = ?` | json,session | `ChatMemoryRepository::updateSlots(int $sessionId, string $json): void` |
| middleware/FreeShippingNudge.php:107 | INSERT | chat_session_memory | `(session_id, summary, slots) VALUES (?, '', ?)` | session,json | `ChatMemoryRepository::insertSlots(int $sessionId, string $json): void` (distinct from saveSession upsert) |
| middleware/FreeShippingNudge.php:114 | SELECT | chat_session_memory | slots | session_id | `ChatMemoryRepository::slotsJson` [dup-SLOTS] |

**BLOCKER / ARCHITECTURE RULE (verified):** `tests/unit/ArchitectureTest.php` line 16: "harness may use service, model and core (never repository directly)"; `DOMAIN = ['service','model','core']`. `src/harness/README.md`: each component may use only service/model/core plus the listed edges; `memory/` and `middleware/` may NOT reference `repository/`. So `ChatbotMemory`, `ChatbotSessionContext`, `PdoChatbotConversationStore`, `FreeShippingNudge` cannot call `ChatSessionRepository`/`ChatMemoryRepository`/`ChatConversationRepository` directly. Options: (a) put thin service-layer wrappers in `src/service/chat/` (e.g. ChatSessionService) that wrap the repositories and have harness depend on them, (b) update the ArchitectureTest + README (spec §3.1) to allow `repository` for `memory` and `middleware` - the CLAUDE.md says "a violation means fix the code, not the test", so (a) is the safe route, or (c) inject repository objects via constructors from `AgentFactory` (AgentFactory is outside harness; but the harness classes would still type-hint the repository class, which counts as a reference). Also ArchitectureTest forbids functions > 80 lines and env reads in harness.

### 4.6 Existing + excluded

| file | note |
| --- | --- |
| src/repository/OutfitCatalogRepository.php | 3 calls: `SELECT id, category_id, name, price, stock, image, style_tags FROM products ORDER BY id` (query l.12), `SELECT product_id, size_name FROM product_sizes` (l.~45), `SELECT product_id, size FROM product_variants WHERE is_active = 1 AND size IS NOT NULL AND (stock IS NULL OR stock > 0)` (l.~51, try/catch PDOException). Already follows pattern. Candidate to reuse: `SizeRepository::allProductSizes()` vs this private `sizesByProduct()` (near-dup of CatalogVariantBackfill:71). |
| src/core/database/MigrationRunner.php | EXCLUDED (6 calls: migrations table, advisory lock, DDL). |
| src/core/observability/LangfuseTraceOutbox.php (1), LangfuseOtlpTracePublisher.php (3) | EXCLUDED (table `langfuse_trace_outbox`). |

## 5. Duplicates (same / near-identical SQL in multiple files -> one shared method)

| Key | SQL | Sites |
| --- | --- | --- |
| dup-STOCK | `SELECT stock FROM products WHERE id = ?` | public/add_to_cart.php:20, public/process_checkout.php:25, CartService:100 (concatenated, uses ->query) |
| dup-PRICE | `SELECT price FROM products WHERE id = ?` | process_checkout.php:36 and :49 |
| dup-PRODBYID | `SELECT * FROM products WHERE id = ?` | public/product.php:10, admin/edit_product.php:9 |
| dup-SIZES | `SELECT * FROM product_sizes WHERE product_id = ?` | public/product.php:21, Products/detail.php:25, Products/sizes.php:8, ProductService:368 |
| dup-RATING | `SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?` | Products/detail.php:32, ProductService:375 |
| dup-PRODINSERT | 8-col `INSERT INTO products (name, price, stock, description, category_id, subcategory_id, image, style_tags)` | admin/manage_products.php:43, Admin/product_create.php:19 |
| dup-IMG / dup-PRODDEL / dup-ADMINPRODS | `SELECT image FROM products WHERE id = ?`, `DELETE FROM products WHERE id = ?`, `SELECT * FROM products ORDER BY id DESC` | admin/manage_products.php:69,77,83 == Admin/product_delete.php:10,18 / Admin/products.php:6 |
| dup-EXISTS | `SELECT id FROM products WHERE id = ?` | Admin/product_update.php:35, Products/add_review.php:17 |
| dup-COUNT/REVENUE | `COUNT(*)` of products/orders/users, `SUM(total_price)... 'Đã hoàn thành'` | admin/index.php:14,17,20,24 == Admin/dashboard.php:7,8,9,11 |
| dup-ADMINORDERS | `SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC` | admin/manage_orders.php:24, Admin/orders.php:6 |
| dup-ORDERSTATUS | `UPDATE orders SET status = ? WHERE id = ?` | admin/manage_orders.php:17, Admin/order_status.php:17 |
| dup-USERS | `SELECT id, username, email, role, status, created_at FROM users ORDER BY id DESC` | admin/manage_users.php:49, Admin/users.php:6 |
| dup-USERSTATUS/ROLE | `UPDATE users SET status = 0/1 WHERE id = ?`, `UPDATE users SET role = ? WHERE id = ?` | admin/manage_users.php:22,26,42 == Admin/user_action.php:16,22,31 |
| dup-SETTOKEN | `UPDATE users SET api_token = ? WHERE id = ?` | public/login.php:55, Auth/login.php:32 |
| dup-ADMINLOGIN | `SELECT * FROM users WHERE (email = ? OR username = ?) AND role = 'admin' LIMIT 1` / `(username = ? OR email = ?)` | public/login.php:22, admin/login.php:30 (arg order only) |
| dup-EMAILEXISTS | `SELECT id FROM users WHERE email = ?` | public/register.php:18 (rowCount), Auth/register.php:24 (fetch) |
| dup-USERINSERT (near) | `INSERT INTO users (username, email, password, api_token [, role, status])` | public/register.php:25, Auth/register.php:33 |
| dup-CATLIST/SUBCATS | `SELECT id, name FROM categories ORDER BY id`; `product_subcategories` join | admin/edit_product.php:14,15 == admin/manage_products.php:11,12 |
| dup-REVIEWADD | `INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)` | public/submit_review.php:15, Products/add_review.php:21 |
| dup-CARTLINE | `SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?` | public/add_to_cart.php:29, CartService:55 |
| dup-CARTQTY | `UPDATE cart SET quantity = ? WHERE id = ?` | public/add_to_cart.php:35, CartService:104 |
| dup-CARTDEL | `DELETE FROM cart WHERE id = ? AND user_id = ?` | public/cart.php:18, CartService:118 |
| dup-CARTCLEAR | `DELETE FROM cart WHERE user_id = ?` | public/process_checkout.php:58, OrderService:109 |
| dup-CARTITEMS (near) | cart JOIN products lines | public/cart.php:25, public/checkout.php:13, CartService:11 (3 slightly different column lists) |
| dup-ORDERITEM | `INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)` | process_checkout.php:53, OrderService:99 |
| dup-STATS | `SELECT SUM(total_price) AS total_spent, COUNT(*) AS completed_count FROM orders WHERE user_id = ? AND status = 'Đã hoàn thành'` | public/profile.php:33, OrderService:40 |
| dup-ORDERLIST (near) | dynamic `SELECT ... FROM orders WHERE user_id = ? [AND status = ?] ORDER BY created_at DESC` | public/profile.php (`*`), OrderService:37 (4 cols) |
| dup-LATESTSESSION | `SELECT id, session_token FROM chat_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1` | Chatbot/history.php:14, ChatbotSessionContext:28 |
| dup-TOKENUSER (near) | `SELECT ... FROM users WHERE api_token = ?` | middleware.php:15 (5 cols), ChatbotSessionContext:21 (`id`) |
| dup-SIZEGUIDE (near) | `SELECT * FROM size_guides [WHERE category_id = ?] ORDER BY [category_id,] FIELD(size_name,'S','M','L','XL')` | SizeGuide/index.php:14,17, SizeService:42,45 (the latter composes `$orderBy`) |
| dup-ALLSIZES (near) | `SELECT product_id, size_name FROM product_sizes [ORDER BY product_id, id]` | CatalogVariantBackfill:71, OutfitCatalogRepository (existing) |
| dup-SLOTS | `SELECT slots FROM chat_session_memory WHERE session_id = ?` | FreeShippingNudge:98, :114 |
| dup-REVIEWS (differs!) | reviews+users join: `r.id = u.id` (public/product.php:26, **bug**) vs `r.user_id = u.id` (Products/reviews.php:8) |
| dup-SEARCH (logic) | in_stock EXISTS block and color EXISTS block appear in both Products/list.php and ProductService::executeSearchProductsDirect (text same, different aliases/semantic: service color uses OR NOT EXISTS) |
| dup-CARDS (near) | `products p LEFT JOIN categories c LEFT JOIN product_subcategories sc` with differing column lists: Products/list.php:201 (no description), ProductService:315 (+ description), ProductService:353 (no price/stock), Products/detail.php:8 (`p.*`) |

## 6. Repository catalogue (deduplicated methods + callers)

Constructor for all: `final class XRepository { public function __construct(private PDO $pdo) {} }`.

### ProductRepository
| Method | Callers |
| --- | --- |
| `findById(int $id): ?array` | public/product.php, admin/edit_product.php |
| `findWithTaxonomy(int $id): ?array` (p.*, category/subcategory) | Products/detail.php |
| `detailStatic(int $id): ?array` | ProductService::fetchProductDetailStatic |
| `idAndStock(int $id): ?array` | CartService::add |
| `stockById(int $id): int` | add_to_cart.php, process_checkout.php, CartService::update |
| `priceById(int $id)` | process_checkout.php (x2) |
| `priceAndStock(int $id): ?array` | ProductService::fetchProductFreshById |
| `exists(int $id): bool` | Admin/product_update.php, Products/add_review.php |
| `imageById(int $id): ?string` | admin/manage_products.php, Admin/product_delete.php |
| `count(): int` | admin/index.php, Admin/dashboard.php |
| `allNewestFirst(): array` | admin/manage_products.php, Admin/products.php |
| `insert(array $data): int` | admin/manage_products.php, Admin/product_create.php |
| `insertBasic(...)` (5 cols) | admin/add_product.php |
| `updateAll(int $id, array $fields): bool` | admin/edit_product.php |
| `updatePartial(int $id, array $fields): int` (whitelist inside) | Admin/product_update.php |
| `delete(int $id): int` | admin/manage_products.php, Admin/product_delete.php |
| `decrementStock(int $id, int $qty): bool` | OrderService::create |
| `searchByNameAndCategory(string $search, $categoryId): array` | public/index.php |
| `searchCount(array $filters): int`, `searchPage(array $filters, string $sort, ?int $limit, int $offset): array` | Products/list.php |
| `searchForAgent(array $args): array` | ProductService::executeSearchProductsDirect |
| `cardsByIds(array $ids): array` | ProductService::fetchFreshProductsByIds |
| `allForBackfill(): array` | CatalogVariantBackfill::run |

### CategoryRepository
`all(): array` (public/index.php), `allOrdered(): array` (Categories/list.php), `idNameList(): array` (admin/edit_product.php, manage_products.php), `subcategoriesWithCategory(): array` (admin/edit_product.php, manage_products.php), `taxonomyList(): array` (ProductService::categories).

### SizeRepository (product_sizes + size_guides)
`sizesForProduct(int $productId): array` (public/product.php, Products/detail.php, Products/sizes.php, ProductService), `sizesForProducts(array $ids): array` (ProductService::attachProductSizes), `allProductSizes(): array` (CatalogVariantBackfill, optionally OutfitCatalogRepository), `guidesByCategory(int $categoryId, bool $sqlite = false)`, `allGuides(bool $sqlite = false)` (SizeGuide/index.php, SizeService), `allForKnowledge(): array` (KnowledgeRetriever).

### VariantRepository (product_variants + colors) - new
`activeByProductIds(array $ids): array` (CatalogVariantHydrator), `colorKeyMap(): array`, `exists(int,string): bool`, `keysForProduct(int): array`, `legacyRows(int): array`, `rekey(int,string,int): void`, `insertLegacy(int,string,?int,?string): void` (all CatalogVariantBackfill). (OutfitCatalogRepository's variant read stays or moves here.)

### ReviewRepository
`forProduct(int $productId): array` (public/product.php; join `r.id = u.id` bug), `forProductWithUsername(int $productId): array` (Products/reviews.php), `ratingSummary(int $productId): array` (Products/detail.php, ProductService), `add(int,int,int,string): void` (public/submit_review.php, Products/add_review.php).

### CartRepository
`itemsWithProduct(int $userId): array` (CartService::list; public/cart.php; public/checkout.php variants), `checkoutSummary(int $userId): array`, `linesByUser(int $userId): array` (process_checkout), `findLine(int,int): ?array` (add_to_cart.php, CartService::add), `findOwnedLine(int,int): ?array` (CartService::update), `insertLine(int,int,int): int`, `insertLineWithSize(int,int,int,string): int`, `setQuantity(int,int): void` (add_to_cart.php, CartService), `updateQuantityAndSize(int,int,string)`, `setSize(int,string)`, `setQuantityForUser(int,int,int)`, `setSizeForUser(int,int,string)` (update_cart.php), `deleteLine(int $cartId, int $userId): int` (cart.php, CartService::remove), `clear(int $userId): void` (process_checkout, OrderService).

### OrderRepository
`listForUser(int $userId, ?string $status): array` (OrderService, profile.php), `completedStats(int $userId): array` (OrderService, profile.php), `findForUser(int $orderId, int $userId): ?array` (OrderService::detail, order_details.php), `itemsForOrder(int $orderId): array` (OrderService; order_details.php uses missing `order_details` table), `cartLinesWithStockForUpdate(int $userId): array` (OrderService::create), `insertOrder(int,float): int` (OrderService), `insertOrderPending(int,float): int` (process_checkout), `insertItem(int,int,int,float): void` (OrderService, process_checkout), `count(): int`, `completedRevenue(): float`, `allWithUsername(): array`, `updateStatus(int,string): int` (admin pages + Admin/order_status.php), `recentPurchases(int $userId, int $limit = 10): array` (ChatbotMemory::loadPurchaseHistory).

### UserRepository
`findByEmail(string)`, `findByEmailOrUsername(string)` (Auth/login), `findAdminByLogin(string)` (public/login.php, admin/login.php), `findByApiToken(string)` (middleware.php), `idByApiToken(string): ?int` (ChatbotSessionContext), `emailExists(string): bool`, `create(...)` (both register paths; variants differ), `setApiToken(int,string)`, `clearApiToken(int)`, `count()`, `listForAdmin()`, `setStatus`/`lock`/`unlock`, `setRole(int,string)`.

### FaqRepository
`search(?string $category, string $search): array` (Faq/index.php), `allForKnowledge(): array` (KnowledgeRetriever).

### OutfitCatalogRepository (existing) / OutfitSuggestionRepository
Existing `all()` unchanged. New (legacy table `outfit_suggestions`): `suggestions(?int $productId, string $search): array` (Outfit/index.php). Suggest a separate small repository since the existing one is UC-A catalogue only.

### ChatSessionRepository (chat_sessions)
`latestActiveForUser(int): ?array` (Chatbot/history.php, ChatbotSessionContext), `findActiveByToken(string): ?int`, `create(?int,string): int` (x2 SQL forms), `attachUser(int,int)`, `touch(int,?int)`, `touchUpdatedAt(int)` (ConversationStore, with sqlNow driver branch).

### ChatConversationRepository (chat_messages + tool_executions)
`messagesForSession(int)` (Chatbot/history.php), `recentBotMetadata(int,int=8)`, `addUserMessage(int,string,?string)`, `addBotMessage(...)`, `logToolExecution(...)` (PdoChatbotConversationStore).

### ChatMemoryRepository (chat_session_memory + user_long_term_memory)
`ensureSessionMemoryTable()`, `ensureLongTermTable()`, `sessionMemory(int)`, `longTerm(int)`, `saveSession(int,string,string)` (driver branch), `saveLongTerm(int,array)` (driver branch), `slotsJson(int): ?string`, `updateSlots(int,string)`, `insertSlots(int,string)` (FreeShippingNudge). Callers: ChatbotMemory, FreeShippingNudge.

(There is no existing `Database`/`Connection` abstraction - all repositories take PDO.)

## 7. SQLite fixture coverage

`tests/support/SqliteShop.php` (`SqliteShop::pdo()` = `sqlite::memory:`, ERRMODE_EXCEPTION) creates ONLY 5 tables:
- `products` (id, category_id, name, price, stock, description, image, style_tags) - NO subcategory_id, NO created_at
- `product_sizes` (id, product_id, size_name)
- `size_guides` (id, product_id, category_id, size_name, height_from/to, weight_from/to, description)
- `cart` (id, user_id, product_id, quantity, size)
- `chat_session_memory` (session_id, summary, slots, created_at, updated_at)

Helpers: `product()`, `sizeGuides()`. Other tests add tables inline: `product_variants` (OutfitCatalogRepositoryTest), `langfuse_trace_outbox` (TraceOutboxTest), `size_guides` redefinition (ToolRegistryDelegationTest), `_migrations`-style tables (MigrationRunnerTest).

NOT in the fixture, so any repository whose unit tests need SQLite must add them (or be tested only in integration `tests/integration/*` against MariaDB `shop_test`): categories, product_subcategories, colors, product_variants, reviews, users, orders, order_items, faqs, outfit_suggestions, chat_sessions, chat_messages, user_long_term_memory, tool_executions. Fixture supports directly: ProductRepository (limited cols), SizeRepository, CartRepository, ChatMemoryRepository (slots/session only).

Important sqlite constraints: `FIELD()` not available (SizeService passes `isSqlite()` branch), MATCH AGAINST not available (Products/list.php only on mysql), `FOR UPDATE` omitted on sqlite (OrderService), `ON CONFLICT` vs `ON DUPLICATE KEY`, `NOW()` vs `CURRENT_TIMESTAMP` - keep these driver branches inside the repositories.

Integration tests present: `tests/integration/AgentAssemblyTest.php`, `CatalogColorSearchTest.php`, `OutfitCatalogIntegrationTest.php`. Eval (`scripts/run_agent_eval.php`) uses shop_test (MariaDB) and covers outfit/freeship/guardrail/unrelated paths (cart lines, products, chat_session_memory).

## 8. Gotchas for the planner

1. `public/order_details.php:21` queries nonexistent table `order_details` (schema has `order_items`). Page is already broken; decide whether to fix the SQL or move verbatim.
2. `public/product.php:26` joins `reviews r JOIN users u ON r.id = u.id` (wrong key) vs API `r.user_id = u.id`. Different SQL text; two methods or fix.
3. `public/process_checkout.php` is a second, divergent checkout (status 'pending', no stock decrement, no FOR UPDATE) vs `OrderService::create` ('Chờ xử lý', decrement). Moving SQL verbatim keeps both; consider routing to OrderService afterwards.
4. Literals baked in SQL (role 'user'/'bot' in chat_messages inserts, status 0/1, `'legacy|%'`, `LIMIT 8/10`) -> keep distinct methods or parametrise carefully if text must stay verbatim.
5. `ProductService::executeSearchProductsDirect` runs the same prepared SQL twice (l.264 and l.275) - repository can expose one `searchForAgent()` and let service reuse rows; behaviour-preserving.
6. `CartService::update` l.100 uses `->query('... WHERE id = ' . (int) ...)` string concat - safe (int cast) but not prepared.
7. Runtime DDL in `ChatbotMemory::ensureSchema()` (CREATE TABLE IF NOT EXISTS via `exec`) – also defined in database/schema.sql. Keep in `ChatMemoryRepository::ensure*` or remove.
8. Several `try { ... } catch (Throwable)` wrappers swallow DB errors (ProductService sizes/rating, KnowledgeRetriever, ChatbotMemory, ConversationStore, Hydrator, OutfitCatalogRepository variants). Keep the try/catch in the same layer (service decides) or inside repository; must preserve behavior in sqlite tests where tables are missing (e.g., product_variants, reviews).
9. harness -> repository dependency: verify `tests/unit/ArchitectureTest.php` + `src/harness/README.md` (layer rules: harness may not reference app/agent/llm_providers; `src/repository` not mentioned in my read - needs a check before moving harness SQL).
10. After adding classes run `scripts/dev/composer dump-autoload` (classmap). Pages need only `new XRepository($pdo)`.
