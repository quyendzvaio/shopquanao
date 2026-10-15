# Outfit Tags + Free-ship Nudge Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Gỡ Stylitics/FindMine/UC1/UC2 và eval cũ, tái tổ chức codebase vào `public/` + `src/`, rồi thêm UC-A (phối set theo tag, ngân sách, size) và UC-C (gợi ý thêm món để được free ship).

**Architecture:** Chatbot giữ pipeline deterministic hiện có (parser → manifest `config/chatbot_tools.php` → ToolPlanner/PlanValidator → tool → EvidenceNormalizer → ResponseGenerator). Mỗi UC mới là một intent + một tool trong manifest. Logic nghiệp vụ nằm trong các class thuần, test được bằng fixture (`OutfitBuilder`, `FreeShippingGapAdvisor`), còn dữ liệu đọc qua repository PDO. Tag phối đồ nằm trong một cột `products.style_tags`, được gắn sẵn trong migration.

**Tech Stack:** PHP 8.2 (không có trên host, chạy qua Docker), PHPUnit 11, PHPStan level 1, MariaDB 10.11 (test unit dùng SQLite in-memory), TypeScript MCP server (node:test), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md` (đã duyệt).

---

## Quy ước dùng trong toàn bộ plan

- Nhánh: `feature/outfit-tags-freeship` (đã tạo từ `develop`).
- Host **không có PHP/Composer**. Task 1 tạo wrapper `scripts/dev/php` và `scripts/dev/composer`; mọi lệnh PHP trong plan đều dùng hai wrapper này.
- Lệnh kiểm tra chuẩn (gọi tắt là **"bộ cổng"**):
  ```bash
  scripts/dev/php vendor/bin/phpunit --testsuite=Unit
  scripts/dev/php vendor/bin/phpstan analyse --level=1 --no-progress $(jq -r '.scripts.stan' composer.json | sed 's/phpstan analyse --level=1 //')
  npm --prefix mcp-server test && npm --prefix mcp-server run build
  docker compose config --quiet
  ```
  Kết quả mong đợi: PHPUnit `OK`, PHPStan `[OK] No errors`, node test `fail 0`, compose không in gì.
- Integration test (cần DB `shop_test` trong Compose): `scripts/dev/test-integration.sh` (Task 2).
- Commit message theo Conventional Commits tiếng Anh, luôn kết thúc bằng dòng:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  ```
- Không sửa migration đã chạy. Migration mới đặt tên `YYYY_MM_DD_name.sql`.
- Code mới khớp phong cách xung quanh: class `final`, không namespace, comment ngắn bằng tiếng Anh, thông điệp cho người dùng bằng tiếng Việt.

---

# GIAI ĐOẠN 0: Chuẩn bị

### Task 1: Wrapper chạy PHP/Composer qua Docker

**Files:**
- Create: `scripts/dev/php`
- Create: `scripts/dev/composer`

**Step 1: Tạo `scripts/dev/php`**

```bash
#!/usr/bin/env bash
# Run PHP from the app image for hosts without a local PHP 8.2 toolchain.
# DEV_DOCKER_NETWORK joins the Compose network (integration tests use DB_HOST=db).
set -euo pipefail
cd "$(dirname "$0")/../.."
exec docker run --rm -i -u "$(id -u):$(id -g)" -v "$PWD":/w -w /w \
  ${DEV_DOCKER_NETWORK:+--network "$DEV_DOCKER_NETWORK"} \
  -e APP_ENV -e DB_HOST -e DB_NAME -e DB_USER -e DB_PASS -e CHATBOT_TOOL_TRANSPORT \
  -e FREE_SHIPPING_THRESHOLD -e FREESHIP_NUDGE_MAX_GAP \
  --entrypoint php "${DEV_PHP_IMAGE:-shop_quan_ao-app:latest}" "$@"
```

**Step 2: Tạo `scripts/dev/composer`**

```bash
#!/usr/bin/env bash
# Run Composer in a throwaway container; the project has no host PHP toolchain.
set -euo pipefail
cd "$(dirname "$0")/../.."
exec docker run --rm -i -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app \
  -e COMPOSER_HOME=/tmp/composer "${DEV_COMPOSER_IMAGE:-composer:2.8}" "$@" --ignore-platform-reqs
```

**Step 3: Cấp quyền chạy và kiểm tra baseline**

```bash
chmod +x scripts/dev/php scripts/dev/composer
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test
```
Expected: `OK (50 tests, 196 assertions)`; node test `fail 0`.

**Step 4: Commit**

```bash
git add scripts/dev/php scripts/dev/composer
git commit -m "chore(dev): run php and composer through docker wrappers"
```

### Task 2: Script dựng DB `shop_test` và chạy integration test

**Files:**
- Create: `scripts/dev/test-db.sh`
- Create: `scripts/dev/test-integration.sh`

**Step 1: Tạo `scripts/dev/test-db.sh`**

```bash
#!/usr/bin/env bash
# Recreate shop_test inside the Compose MariaDB, import the base schema and
# apply every migration, mirroring the CI integration job.
set -euo pipefail
cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a
ROOT_PW="${MARIADB_ROOT_PASSWORD:-root_pass}"
SCHEMA_FILE="${SCHEMA_FILE:-$( [ -f database/schema.sql ] && echo database/schema.sql || echo sql/shop_db.sql )}"
docker compose exec -T db mariadb -uroot -p"$ROOT_PW" \
  -e 'DROP DATABASE IF EXISTS shop_test; CREATE DATABASE shop_test CHARACTER SET utf8mb4;'
sed '/^CREATE DATABASE/d;/^USE /d' "$SCHEMA_FILE" | docker compose exec -T db mariadb -uroot -p"$ROOT_PW" shop_test
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$ROOT_PW" \
  scripts/dev/php scripts/run_database_migrations.php
```

**Step 2: Tạo `scripts/dev/test-integration.sh`**

```bash
#!/usr/bin/env bash
# Run the Integration suite against shop_test (call scripts/dev/test-db.sh first).
set -euo pipefail
cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a
DEV_DOCKER_NETWORK=shop_quan_ao_default APP_ENV=test CHATBOT_TOOL_TRANSPORT=internal \
  DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="${MARIADB_ROOT_PASSWORD:-root_pass}" \
  scripts/dev/php vendor/bin/phpunit --testsuite=Integration "$@"
```

**Step 3: Chạy thử**

```bash
chmod +x scripts/dev/test-db.sh scripts/dev/test-integration.sh
scripts/dev/test-db.sh && scripts/dev/test-integration.sh
```
Expected: in ra "Database migrations are up to date." (runner hiện tại là bản giả, Task 12 sửa), integration `OK`.

**Step 4: Commit**

```bash
git add scripts/dev/test-db.sh scripts/dev/test-integration.sh
git commit -m "chore(dev): scripts to provision shop_test and run integration tests"
```

### Task 3: Commit spec và plan

```bash
git add docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md docs/plans/2026-10-15-outfit-tags-freeship.md
git commit -m "docs: spec and plan for outfit tags and free-ship nudge"
```

---

# GIAI ĐOẠN 1: Gỡ bỏ (spec §3)

> Giai đoạn này chỉ xoá. Đúng là xoá xong khi bộ cổng vẫn xanh và lệnh `grep` ở Task 10 không trả về gì.

### Task 4: Gỡ UC2 (proactive cart styling)

**Files:**
- Modify: `api/controllers/chatbot/ChatbotService.php`
- Modify: `api/controllers/internal/mcp.php`
- Modify: `api/services/CartService.php`
- Modify: `api/controllers/chatbot/index.php:51`, `api/controllers/chatbot/stream.php:48-49`
- Modify: `mcp-server/src/shop-client.ts:65-73`, `mcp-server/src/chat-stream.ts:181`

**Step 1: `ChatbotService.php`**
- Xoá 3 dòng `require_once __DIR__ . '/../../services/Fashion/Proactive*.php'`.
- Xoá 2 dòng gọi `$result = $this->attachProactiveStyling($result);` (trong `respond()` và `respondStreaming()`).
- Xoá cả method `private function attachProactiveStyling(array $result): array { ... }`.

**Step 2: `internal/mcp.php`**
- Xoá 3 dòng `require_once` tới `services/Fashion/Proactive*` và dòng `require_once __DIR__ . '/../chatbot/ToolRegistry.php';`.
- Xoá cả khối `if ($operation === 'agent.proactive_turn') { ... }`.

**Step 3: `CartService.php`**
- Xoá 2 dòng `require_once __DIR__ . '/Fashion/ProactiveStylingState*.php';` và mọi `require_once` tới `Fashion/`.
- Trong `add()`, xoá lời gọi `$this->publishCartItemAdded(...)`.
- Xoá method `publishCartItemAdded()`. Chạy `grep -n "resolveSessionId" api/services/CartService.php`; nếu chỉ còn dòng khai báo thì xoá luôn method `resolveSessionId()`, và xoá tham số `session_id` khỏi `add()` nếu không còn được dùng.

**Step 4: Response chatbot**
- `api/controllers/chatbot/index.php:51`: bỏ `'proactive_styling', 'proactive_status', 'proactive_styling_metrics'` khỏi mảng key.
- `api/controllers/chatbot/stream.php:48-49`: xoá hai dòng `'proactive_styling' => ...` và `'proactive_status' => ...`.

**Step 5: MCP**
- `mcp-server/src/shop-client.ts`: xoá hàm `advanceProactiveTurn` (từ dòng 65 tới hết hàm).
- `mcp-server/src/chat-stream.ts:181`: xoá dòng `if (typeof upstream.proactive_styling === 'boolean') ...`.

**Step 6: Kiểm tra**

```bash
grep -rn "Proactive\|proactive_" api mcp-server/src mcp-server/tests config
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test && npm --prefix mcp-server run build
```
Expected: `grep` chỉ còn các file trong `api/services/Fashion/` (xoá ở Task 6); test xanh.

**Step 7: Commit**

```bash
git add -A api mcp-server
git commit -m "refactor(chatbot): remove UC2 proactive cart styling path"
```

### Task 5: Gỡ tool `suggest_complementary_products` (UC1)

**Files:**
- Modify: `config/chatbot_tools.php` (xoá entry `suggest_complementary_products`)
- Modify: `api/controllers/chatbot/pipeline/DeterministicIntentParser.php`
- Modify: `api/controllers/chatbot/pipeline/{ToolPlanner,PlanValidator,CapabilityRegistry,LightweightEvidenceScorer,EvidenceNormalizer,ResponseGenerator,LlmInputParser}.php`
- Modify: `api/controllers/chatbot/ToolDefinitionCatalog.php`, `api/controllers/chatbot/ToolRegistry.php`
- Modify: `api/services/ProductService.php`, `api/services/ToolApplicationService.php`
- Modify: `mcp-server/src/tools.ts`, `mcp-server/tests/contract.test.ts`, `mcp-server/tests/chat-stream.test.ts:61`
- Test: `tests/Unit/ChatbotGuardrailTest.php`, `tests/Unit/ManifestPlannerTest.php`

**Step 1: Sửa test trước (đỏ)**

`tests/Unit/ChatbotGuardrailTest.php`: thay `testExplicitAnchorStylingRequestKeepsProductId` bằng:

```php
    public function testAnchoredStylingRequestKeepsProductIdWithoutProviderIntent(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('Sản phẩm mã 52 phối với gì?', [])
            ->toArray();
        $this->assertNotSame(
            'suggest_complementary_products',
            (string)($partial['resolved_fields']['intent']['value'] ?? '')
        );
        $this->assertSame(52, (int)($partial['resolved_fields']['product_id']['value'] ?? 0));
    }
```

`tests/Unit/ManifestPlannerTest.php`: xoá dòng `'suggest_complementary_products' => ['product_id' => 52],` trong `testPlannerOutputMatchesManifest`, rồi thêm test:

```php
    public function testManifestNoLongerDeclaresProviderStylingIntent(): void
    {
        $this->assertNotContains('suggest_complementary_products', ChatbotToolManifest::knownIntents());
    }
```

`mcp-server/tests/contract.test.ts`: xoá `'suggest_complementary_products', ` khỏi mảng tên tool mong đợi.
`mcp-server/tests/chat-stream.test.ts:61`: đổi `primary_intent: 'suggest_complementary_products'` thành `primary_intent: 'product_search'`.

**Step 2: Chạy để thấy đỏ**

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit --filter 'ChatbotGuardrailTest|ManifestPlannerTest'
npm --prefix mcp-server test
```
Expected: FAIL (intent vẫn là `suggest_complementary_products`; manifest vẫn khai báo; MCP vẫn liệt kê tool).

**Step 3: Gỡ code**

- `config/chatbot_tools.php`: xoá khối `'suggest_complementary_products' => [...]`.
- `DeterministicIntentParser.php`: trong `inferIntent()` xoá khối `if (isset($fields['product_id']) && $this->isComplementaryRequest($lower)) { return 'suggest_complementary_products'; }`; xoá method `isComplementaryRequest()`.
- `ToolPlanner.php`: xoá `case 'suggest_complementary_products': ...` (4 dòng) và dòng `if ($primary === 'suggest_complementary_products') return null;` trong `case 'search_products'`.
- `PlanValidator.php`: xoá khối `if ($primary === 'suggest_complementary_products') { ... }`.
- `CapabilityRegistry.php`: xoá arm `'suggest_complementary_products' => ['suggest_complementary_products'],` trong `relevantForPartial()` và khối metadata `'suggest_complementary_products' => [...]` trong `knownMetadata()`.
- `LightweightEvidenceScorer.php`: xoá dòng `'suggest_complementary_products' => ['complementary_products'],` và dòng `'complementary_products' => ...` trong `hasFact()`.
- `EvidenceNormalizer.php`: xoá `$complementaryGroups = [];`, nhánh `elseif ($tool === 'suggest_complementary_products')`, key `'complementary_groups' => $complementaryGroups`, và method `normalizeComplementary()`.
- `ResponseGenerator.php`: xoá `$complementaryGroups = ...`, arm `'suggest_complementary_products' => ...` trong `match`, và method `complementaryAnswer()`.
- `LlmInputParser.php`: xoá `'suggest_complementary_products',` khỏi `INTENTS`; dòng 224 đổi thành `if ($intent === 'product_detail' && $result['product_id'] === null) {`; trong system prompt, xoá cụm `, và phối đồ cho product_id cụ thể`.
- `ToolDefinitionCatalog.php`: xoá `self::definition('suggest_complementary_products', ...)`.
- `ToolRegistry.php`: xoá method `executeSuggestComplementaryProducts()` và **mọi** dòng `require_once __DIR__ . '/../../services/Fashion/...'`.
- `ProductService.php`: xoá method `suggestComplementary()`.
- `ToolApplicationService.php`: xoá arm `'suggest_complementary_products' => ...`.
- `mcp-server/src/tools.ts`: xoá khối `server.registerTool('suggest_complementary_products', {...});`.

**Step 4: Chạy lại**

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test && npm --prefix mcp-server run build
grep -rn "complementary" api config mcp-server/src mcp-server/tests tests
```
Expected: tất cả PASS; `grep` chỉ còn `api/services/Fashion/*` (xoá ở Task 6).

**Step 5: Commit**

```bash
git add -A api config mcp-server tests
git commit -m "refactor(chatbot): remove provider-backed complementary styling tool"
```

### Task 6: Xoá code, script, tài liệu của nhà cung cấp

**Step 1: Xoá**

```bash
git rm -r -q api/services/Fashion
git rm -q scripts/consume_fashion_events.php scripts/publish_fashion_outbox.php \
  scripts/findmine_live_inspect.php scripts/import_fashion_provider_mappings.php \
  scripts/run_fashion_extraction_eval.php scripts/run_findmine_agent_eval.php \
  scripts/run_findmine_offline_eval.php scripts/run_stylitics_agent_eval.php \
  scripts/smoke_cart_event_pipeline.php scripts/smoke_findmine_demo.php scripts/smoke_findmine_live.php \
  scripts/smoke_proactive_chat_turns.php scripts/smoke_proactive_demo_live.php \
  scripts/smoke_proactive_live_stream.php scripts/smoke_proactive_ws_turn.mjs scripts/eval_rag_chatbot.py
git rm -q docker/findmine-mcp-shopquanao.patch docs/cart-styling-event-architecture.md \
  docs/fashion-extraction-failure-analysis.md docs/fashion-extraction-pipeline.md \
  docs/catalog-variant-readiness-plan.md docs/catalog-variant-backfill-results.md
```

**Step 2: Dọn tham chiếu còn sót**

- `tests/bootstrap.php`: xoá dòng `require_once ROOT_DIR . '/api/services/Fashion/ProactiveStylingStateMachine.php';`.
- Chạy `grep -rn "services/Fashion\|Fashion/" api config tests scripts`. Mỗi dòng còn lại là một `require_once` chết: xoá.

**Step 3: Kiểm tra**

```bash
scripts/dev/php -l api/services/CartService.php
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
```
Expected: `No syntax errors`; Unit `OK`.

**Step 4: Commit**

```bash
git add -A
git commit -m "chore: delete Stylitics/FindMine services, scripts and docs"
```

### Task 7: Xoá toàn bộ eval cũ

**Step 1: Xoá**

```bash
git rm -r -q eval
```

**Step 2: CI `.github/workflows/ci.yml`**
- Bước "Python lint (reranker)": chỉ giữ `python3 -m py_compile docker/reranker/app.py docker/rag-ml/app.py && echo "✅ Python syntax OK"`.
- Xoá bước "Validate 70-question agent corpus" (bước thay thế thêm ở Task 32).

**Step 3: Kiểm tra**

```bash
grep -rn "eval/\|run_findmine\|ragas" .github Dockerfile docker-compose.yml .dockerignore .gitignore
```
Expected: không còn dòng nào trỏ tới file đã xoá. Riêng `reports/` trong `.gitignore` thì giữ.

**Step 4: Commit**

```bash
git add -A
git commit -m "chore(eval): remove legacy provider eval suites and CI steps"
```

### Task 8: Gỡ worker, biến môi trường, bước deploy

**Files:** `docker-compose.yml`, `Dockerfile`, `.env.example`, `.github/workflows/ci.yml`, `.github/pull_request_template.md`

**Step 1: `docker-compose.yml`**
- Xoá hai service `fashion-outbox-publisher:` và `fashion-event-consumer:`.
- Trong `app.environment`, xoá các dòng `STYLING_PROVIDER`, `STYLITICS_*`, `FINDMINE_*`, `FASHION_PROVIDER`, `FASHION_EVENT_*`.
- Chạy `grep -n "fashion-\|STYLITICS\|FINDMINE\|FASHION_" docker-compose.yml`; mọi kết quả còn lại (ví dụ `depends_on`) đều phải xoá.

**Step 2: `Dockerfile`**: xoá hai dòng `COPY ... scripts/publish_fashion_outbox.php ...` và `COPY ... scripts/consume_fashion_events.php ...`.

**Step 3: `.env.example`**: xoá khối Stylitics (khoảng dòng 43–57) và mọi dòng `FINDMINE_*`, `FASHION_*`, `STYLING_PROVIDER`.

**Step 4: `ci.yml` (job deploy)**: xoá các dòng `echo "FASHION_PROVIDER=...`, `echo "FINDMINE_...`, `echo "FASHION_EVENT_...`. Chạy `grep -n "fashion-outbox\|fashion-event\|FINDMINE\|STYLITICS\|FASHION_" .github/workflows/ci.yml`; xoá phần còn lại (bước build hoặc restart worker nếu có).

**Step 5: `.github/pull_request_template.md`**: xoá các checklist nhắc tới Stylitics/FindMine.

**Step 6: Kiểm tra**

```bash
docker compose config --quiet
grep -rniE "stylitics|findmine|fashion-outbox|fashion-event|FASHION_PROVIDER" docker-compose.yml Dockerfile .env.example .github
```
Expected: `compose config` không in gì; `grep` không trả về dòng nào.

**Step 7: Commit**

```bash
git add -A
git commit -m "chore(deploy): drop fashion event workers and provider env vars"
```

### Task 9: Migration xoá bảng cũ

**Files:**
- Create: `sql/migrations/2026_10_15_drop_styling_providers.sql`

**Step 1: Viết migration**

```sql
-- UC1/UC2 provider styling and the cart-event pipeline were removed
-- (spec 2026-10-15). Drop only the tables those migrations created.
DROP TABLE IF EXISTS fashion_provider_product_mapping;
DROP TABLE IF EXISTS proactive_styling_state;
DROP TABLE IF EXISTS fashion_consumed_events;
DROP TABLE IF EXISTS fashion_event_outbox;
```

**Step 2: Áp dụng trên `shop_test` và kiểm tra**

Runner hiện tại chỉ chạy migration khi include `config/db.php`; `scripts/run_database_migrations.php` có include file này nên vẫn dùng được.

```bash
scripts/dev/test-db.sh
set -a; . ./.env; set +a
docker compose exec -T db mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" shop_test \
  -e "SHOW TABLES LIKE 'fashion%'; SHOW TABLES LIKE 'proactive%'; SELECT name FROM _migrations WHERE name LIKE '2026_10_15%';"
```
Expected: không có bảng `fashion%`/`proactive%`; `_migrations` có `2026_10_15_drop_styling_providers.sql`.

**Step 3: Commit**

```bash
git add sql/migrations/2026_10_15_drop_styling_providers.sql
git commit -m "feat(db): drop provider styling and cart-event tables"
```

### Task 10: Cổng kết thúc giai đoạn 1

```bash
grep -rniE "stylitics|findmine|proactive|complementary|fashion_event|fashion_provider|outbox_publisher" \
  api config scripts tests mcp-server/src mcp-server/tests docker Dockerfile docker-compose.yml .github .env.example
```
Expected: không trả về dòng nào (kết quả trong `docs/`, `README.md`, `CLAUDE.md`, `.claude/` sẽ xử lý ở Task 33).

Chạy **bộ cổng** + `scripts/dev/test-integration.sh`. Tất cả phải xanh. Không cần commit (không có thay đổi).

---

# GIAI ĐOẠN 2: Tái tổ chức codebase (spec §4)

> Mỗi task chỉ dời file hoặc sửa đường dẫn, **không đổi hành vi**. Sau mỗi task chạy bộ cổng.

### Task 11: Composer autoload, `src/bootstrap.php`, bỏ `require_once` của class

**Files:**
- Create: `src/bootstrap.php`
- Create (tạm, không commit): `/tmp/strip_class_requires.php`
- Modify: `composer.json`, `tests/bootstrap.php`, `api/config.php`, `.gitignore` (nếu cần)
- Modify: mọi file PHP còn `require_once` trỏ tới một file khai báo class

**Step 1: `composer.json`**: thay khối `autoload` và script `stan`:

```json
    "autoload": {
        "classmap": [
            "src/",
            "api/cache/",
            "api/controllers/chatbot/",
            "api/services/"
        ]
    },
```
```json
        "stan": "phpstan analyse --level=1 api/ config/ src/",
```

**Step 2: Tạo `src/bootstrap.php`**

```php
<?php

/**
 * Shared entry point for storefront pages, the REST front controller and CLI
 * scripts: Composer's classmap autoloader first, then the PDO connection.
 * Defines $pdo in the including scope.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/db.php';
```

**Step 3: Sinh autoload**

```bash
scripts/dev/composer dump-autoload
```
Expected: `Generated autoload files containing N classes` (N > 100).

**Step 4: Script xoá `require_once` của class.** Tạo `/tmp/strip_class_requires.php` (công cụ dùng một lần, không commit):

```php
<?php
// Remove `require_once <path>;` lines whose target file declares a class,
// interface, trait or enum. Procedural requires (config, controllers,
// templates, data files) are kept. Usage: php strip_class_requires.php <dir>...
$declares = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+\w+/m';
$removed = 0;
foreach (array_slice($argv, 1) as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        $lines = file($path);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match("/^\s*require_once\s+(__DIR__|ROOT_DIR|dirname\(__DIR__\))\s*\.\s*'([^']+)'\s*;\s*$/", $line, $m)) {
                $base = match ($m[1]) {
                    '__DIR__' => dirname($path),
                    'dirname(__DIR__)' => dirname($path, 2),
                    'ROOT_DIR' => getcwd(),
                };
                $target = realpath($base . $m[2]);
                if ($target !== false && preg_match($declares, (string)file_get_contents($target))) {
                    $removed++;
                    continue;
                }
            }
            $out[] = $line;
        }
        if (count($out) !== count($lines)) file_put_contents($path, implode('', $out));
    }
}
fwrite(STDOUT, "removed $removed class requires\n");
```

Chạy (thư mục gốc repo là cwd):

```bash
cp /tmp/strip_class_requires.php ./.strip.php
scripts/dev/php .strip.php api scripts tests
rm .strip.php
```
Expected: `removed N class requires` (N khoảng 200+).

**Step 5: Entry point phải nạp autoload**
- `api/config.php` dòng 3: `require_once __DIR__ . '/../config/db.php';` → `require_once __DIR__ . '/../src/bootstrap.php';`
- Mỗi script còn lại trong `scripts/*.php` có `require ... config/db.php` → đổi thành `require dirname(__DIR__) . '/src/bootstrap.php';`. Script không include `db.php` nhưng dùng class thì thêm dòng đó ngay sau `<?php`/`declare`.
- `tests/bootstrap.php`: còn lại phải đúng như sau (giữ `ROOT_DIR`):

```php
<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/vendor/autoload.php';
```

**Step 6: Kiểm tra**

```bash
for f in $(git ls-files 'api/*.php' 'scripts/*.php' 'tests/*.php'); do scripts/dev/php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
scripts/dev/php vendor/bin/phpstan analyse --level=1 api/ config/ src/ --no-progress
```
Expected: không có `LINT FAIL`; Unit `OK`; PHPStan `[OK]`.

**Step 7: Ảnh Docker có `vendor/`.** Trong `Dockerfile`, thêm `ARG COMPOSER_IMAGE=composer:2.8` cạnh các `ARG` đầu file, thêm stage trước stage `runtime`:

```dockerfile
FROM ${COMPOSER_IMAGE} AS php-deps
WORKDIR /build
COPY composer.json composer.lock ./
COPY src/ src/
COPY api/ api/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
      --ignore-platform-reqs --classmap-authoritative
```

và trong stage runtime, ngay trước dòng `COPY --chown=www-data:www-data api/ api/`:

```dockerfile
COPY --from=php-deps --chown=www-data:www-data /build/vendor/ vendor/
COPY --chown=www-data:www-data src/ src/
```

```bash
docker compose build app && docker compose up -d app && sleep 3
curl -fsS 'http://localhost/api/products?limit=1' | head -c 200
```
Expected: JSON sản phẩm. (Nếu cổng 80 bận: `NGINX_HTTP_PORT=8092` theo `deployment.md`.)

**Step 8: Commit**

```bash
git add -A
git commit -m "refactor: composer classmap autoload replaces manual class requires"
```

### Task 12: `MigrationRunner` thật, `config/db.php` chỉ tạo PDO

**Files:**
- Create: `src/Infrastructure/Database/MigrationRunner.php`
- Test: `tests/Unit/MigrationRunnerTest.php`
- Modify: `config/db.php`, `scripts/run_database_migrations.php`
- Delete: `sql/migrations/001_init.php`
- Move: `sql/migrations/003_admin_user.php` → `scripts/ensure_admin_user.php`

**Step 1: Viết test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/2026_01_01_a.sql', "-- create\nCREATE TABLE a (id INTEGER);\nINSERT INTO a VALUES (1);");
        file_put_contents($this->dir . '/2026_01_02_b.sql', "CREATE TABLE b (id INTEGER);");
        file_put_contents($this->dir . '/legacy.php', "<?php // ignored");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testAppliesPendingSqlFilesOnceInNameOrder(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new MigrationRunner($pdo, $this->dir);

        $this->assertSame(['2026_01_01_a.sql', '2026_01_02_b.sql'], $runner->run());
        $this->assertSame([], $runner->run());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM a')->fetchColumn());
        $this->assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
    }

    public function testSplitStatementsDropsCommentLinesAndBlanks(): void
    {
        $this->assertSame(
            ['CREATE TABLE x (id INTEGER)', 'INSERT INTO x VALUES (1)'],
            MigrationRunner::splitStatements("-- note\nCREATE TABLE x (id INTEGER);\n\nINSERT INTO x VALUES (1);\n")
        );
    }
}
```

**Step 2: Chạy để thấy đỏ**

```bash
scripts/dev/php vendor/bin/phpunit tests/Unit/MigrationRunnerTest.php
```
Expected: FAIL `Class "MigrationRunner" not found`.

**Step 3: Viết `src/Infrastructure/Database/MigrationRunner.php`**

```php
<?php

/**
 * Applies pending *.sql migrations exactly once, tracked by file basename in
 * `_migrations`. Called only from scripts/run_database_migrations.php so the
 * deploy gate (not web requests) owns schema changes. A MariaDB advisory lock
 * serialises concurrent runners.
 */
final class MigrationRunner
{
    private const LOCK_NAME = 'shop_quan_ao_schema_migrations';

    public function __construct(private PDO $pdo, private string $migrationsDir)
    {
    }

    /** @return list<string> basenames applied by this run */
    public function run(): array
    {
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($isMysql) {
            $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
            $lock->execute([self::LOCK_NAME]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('Could not acquire the database migration lock');
            }
        }

        try {
            $this->pdo->exec($isMysql
                ? 'CREATE TABLE IF NOT EXISTS _migrations (
                    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name varchar(255) NOT NULL,
                    applied_at timestamp NOT NULL DEFAULT current_timestamp(),
                    UNIQUE KEY name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                : 'CREATE TABLE IF NOT EXISTS _migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )');

            $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
            sort($files);
            $check = $this->pdo->prepare('SELECT 1 FROM _migrations WHERE name = ?');
            $insert = $this->pdo->prepare('INSERT INTO _migrations (name) VALUES (?)');
            $applied = [];
            foreach ($files as $file) {
                $name = basename($file);
                $check->execute([$name]);
                if ($check->fetchColumn() !== false) {
                    continue;
                }
                foreach (self::splitStatements((string)file_get_contents($file)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert->execute([$name]);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            if ($isMysql) {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
            }
        }
    }

    /**
     * Migrations contain plain DDL/DML without stored procedures, so splitting
     * on ';' after dropping full-line comments is sufficient.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", $sql),
            fn(string $line): bool => !str_starts_with(trim($line), '--')
        );
        $statements = array_map('trim', explode(';', implode("\n", $lines)));
        return array_values(array_filter($statements, fn(string $s): bool => $s !== ''));
    }
}
```

**Step 4: Chạy test (xanh)**

```bash
scripts/dev/composer dump-autoload
scripts/dev/php vendor/bin/phpunit tests/Unit/MigrationRunnerTest.php
```
Expected: `OK (2 tests, 5 assertions)`.

**Step 5: Đưa runner vào dùng**
- `config/db.php`: xoá khối `// Auto-create tables if missing` (cả `try { ... } catch (Exception $e) {}`) và khối `// Run pending migrations`. Xoá luôn thẻ đóng `?>` ở cuối file. File chỉ còn phần tạo `$pdo` (vòng retry).
- `scripts/run_database_migrations.php` thay toàn bộ:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

/** @var PDO $pdo */
$applied = (new MigrationRunner($pdo, dirname(__DIR__) . '/sql/migrations'))->run();
fwrite(STDOUT, $applied === []
    ? "Database migrations are up to date.\n"
    : 'Applied migrations: ' . implode(', ', $applied) . "\n");
```

- `git rm sql/migrations/001_init.php`
- `git mv sql/migrations/003_admin_user.php scripts/ensure_admin_user.php`, rồi thêm vào **đầu** file (sau `<?php`): `require dirname(__DIR__) . '/src/bootstrap.php';`, và vào **cuối** file: `migrateEnsureAdmin($pdo);`.

**Step 6: Kiểm tra end-to-end**

```bash
scripts/dev/test-db.sh
scripts/dev/test-integration.sh
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
```
Expected: `test-db.sh` in `Applied migrations: 002_indexes_cache.sql, ...` (lần đầu trên DB mới); integration + unit `OK`.

**Step 7: Commit**

```bash
git add -A
git commit -m "refactor(db): run migrations only from the deploy gate via MigrationRunner"
```

### Task 13: Smoke HTTP baseline

**Files:**
- Create: `scripts/dev/smoke.sh`

> Lúc viết plan (2026-10-15), container `app` đang **unhealthy** và nginx trả 502 cho mọi trang, vì upstream bị `connection refused`. Phải dựng lại stack trước khi đo baseline.

**Step 1: Dựng lại stack**

```bash
# Remove only the two workers dropped in Task 8. --remove-orphans is avoided on
# purpose: it can also remove the opt-in Langfuse (observability profile) containers.
for svc in fashion-outbox-publisher fashion-event-consumer; do
  docker ps -aq --filter "label=com.docker.compose.service=$svc" | xargs -r docker rm -f
done
docker compose up -d --build app nginx chat-stream
docker compose ps app nginx
```
Expected: `app` chuyển `healthy` trong khoảng 30 giây.

**Step 2: Tạo `scripts/dev/smoke.sh`**

```bash
#!/usr/bin/env bash
# HTTP smoke for storefront pages, admin, REST API, assets and blocked paths.
# Usage: BASE_URL=http://localhost:8092 scripts/dev/smoke.sh
set -uo pipefail
BASE="${BASE_URL:-http://localhost}"
fail=0
check() { # expected_status method path [json_body]
  local want="$1" method="$2" path="$3" body="${4:-}" got
  if [ -n "$body" ]; then
    got=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" -H 'Content-Type: application/json' --data "$body" "$BASE$path")
  else
    got=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" "$BASE$path")
  fi
  if [ "$got" = "$want" ]; then echo "ok   $got $method $path"; else echo "FAIL $method $path: want $want got $got"; fail=1; fi
}
check 200 GET /nginx-health
check 200 GET /
check 200 GET '/product.php?id=50'
check 200 GET /login.php
check 200 GET /register.php
check 200 GET /admin/login.php
check 200 GET /css/style.css
check 200 GET /images/at_basic_01.jpg
check 200 GET '/api/products?limit=1'
check 200 GET /api/products/50
check 200 GET /api/categories
check 200 GET /api/faq
check 200 GET /api/size-guide
check 200 GET /api/outfit
check 404 POST /api/internal/mcp '{}'
check 200 POST /api/chatbot '{"message":"áo thun dưới 300k"}'
exit $fail
```

**Step 3: Chạy và chốt baseline**

```bash
chmod +x scripts/dev/smoke.sh && scripts/dev/smoke.sh
```
Expected: mọi dòng `ok`. Nếu một trang trả mã khác 200 **ngay ở baseline** (ví dụ redirect 302 do chưa đăng nhập), sửa giá trị mong đợi của dòng đó cho khớp hiện trạng **trước khi** dời file. Từ đây smoke là thước đo "không đổi hành vi".

**Step 4: Commit**

```bash
git add scripts/dev/smoke.sh
git commit -m "chore(dev): http smoke script for reorg safety"
```

### Task 14: Dời class vào `src/`

**Step 1: Dời**

```bash
mkdir -p src/Infrastructure/Cache src/Infrastructure/Observability src/Shop/Catalog \
  src/Chatbot/Pipeline src/Chatbot/Llm src/Chatbot/Contracts src/Chatbot/Memory src/Chatbot/Knowledge src/Chatbot/Tools
git mv api/cache/Cache.php src/Infrastructure/Cache/
git mv api/services/Observability/*.php src/Infrastructure/Observability/
git mv api/services/CartService.php api/services/OrderService.php api/services/ProductService.php api/services/SizeService.php src/Shop/
git mv api/services/Catalog/*.php src/Shop/Catalog/
git mv api/controllers/chatbot/ProductAttributeNormalizer.php src/Shop/Catalog/
git mv api/services/KnowledgeService.php api/controllers/chatbot/KnowledgeRetriever.php src/Chatbot/Knowledge/
git mv api/services/ToolApplicationService.php src/Chatbot/Tools/
git mv api/controllers/chatbot/ToolRegistry.php api/controllers/chatbot/ToolDefinitionCatalog.php \
  api/controllers/chatbot/McpToolGateway.php api/controllers/chatbot/ShadowToolGateway.php src/Chatbot/Tools/
git mv api/controllers/chatbot/ChatbotMemory.php api/controllers/chatbot/PdoChatbotConversationStore.php src/Chatbot/Memory/
git mv api/controllers/chatbot/ChatbotService.php api/controllers/chatbot/ChatbotSessionContext.php src/Chatbot/
git mv api/controllers/chatbot/pipeline/*.php src/Chatbot/Pipeline/
git mv api/controllers/chatbot/llm/*.php src/Chatbot/Llm/
git mv api/controllers/chatbot/contracts/*.php src/Chatbot/Contracts/
ls api/services api/cache api/controllers/chatbot
```
Expected: `api/services` và `api/cache` rỗng (git không giữ thư mục rỗng); `api/controllers/chatbot` chỉ còn `index.php stream.php history.php`.

**Step 2: Sửa code phụ thuộc đường dẫn**
- `src/Chatbot/Pipeline/ChatbotToolManifest.php`: cả hai chỗ `require __DIR__ . '/../../../../config/chatbot_tools.php'` → `require dirname(__DIR__, 3) . '/config/chatbot_tools.php'`.
- `src/Chatbot/Knowledge/KnowledgeRetriever.php`: `dirname(__DIR__, 3)` vẫn trỏ về gốc repo (`src/Chatbot/Knowledge` → gốc). Không cần sửa, nhưng phải xác nhận bằng test ở Step 4.
- Chạy `grep -rn "require\b\|include\b" src | grep -v "dirname(__DIR__"`. Mỗi `require`/`include` không `_once` còn trỏ tới class file thì xoá; data file thì sửa đường dẫn.

**Step 3: Autoload chỉ quét `src/`**
- `composer.json` → `"classmap": ["src/"]`.
- `Dockerfile` stage `php-deps`: xoá dòng `COPY api/ api/`.

```bash
scripts/dev/composer dump-autoload
```

**Step 4: Kiểm tra**

```bash
for f in $(git ls-files 'src/*.php'); do scripts/dev/php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
scripts/dev/php vendor/bin/phpstan analyse --level=1 api/ config/ src/ --no-progress
scripts/dev/php -r 'require "vendor/autoload.php"; var_dump(count(ChatbotToolManifest::knownIntents()) > 5);'
scripts/dev/test-integration.sh
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
```
Expected: không có lint fail; tests `OK`; `bool(true)`; smoke toàn `ok`.

**Step 5: Commit**

```bash
git add -A
git commit -m "refactor: move shop, chatbot and infrastructure classes into src/"
```

### Task 15: HTTP layer vào `src/Http`, front controller vào `public/api`

**Step 1: Dời controller**

```bash
mkdir -p src/Http/Controllers public/api
for pair in admin:Admin auth:Auth cart:Cart categories:Categories chatbot:Chatbot faq:Faq \
            internal:Internal knowledge:Knowledge orders:Orders outfit:Outfit products:Products size-guide:SizeGuide; do
  from="${pair%%:*}"; to="${pair##*:}"
  git mv "api/controllers/$from" "src/Http/Controllers/$to"
done
git mv api/config.php src/Http/helpers.php
git mv api/middleware.php src/Http/middleware.php
git mv api/index.php public/api/index.php
```

**Step 2: `src/Http/helpers.php` dòng 3**: `require_once __DIR__ . '/../src/bootstrap.php';` → `require_once dirname(__DIR__) . '/bootstrap.php';`. Sửa comment dòng 2 thành `// Shared JSON helpers for REST controllers; loads the app bootstrap.`

**Step 3: `src/Http/middleware.php`**: `require_once __DIR__ . '/config.php';` → `require_once __DIR__ . '/helpers.php';`

**Step 4: Tách bảng route.** Tạo `src/Http/routes.php`:

```php
<?php

/**
 * REST routing table: 'METHOD /regex' => controller file under Http/Controllers.
 * The front controller (public/api/index.php) matches in declaration order.
 */
return [
    // ... (dán nguyên mảng $routes từ public/api/index.php vào đây)
];
```

Cắt nguyên khối `$routes = [ ... ];` khỏi `public/api/index.php` và dán phần tử vào mảng trên. Rồi đổi tiền tố handler cho khớp tên thư mục mới:

```bash
sed -i -E "s#=> '(admin)/#=> 'Admin/#; s#=> 'auth/#=> 'Auth/#; s#=> 'cart/#=> 'Cart/#; \
  s#=> 'categories/#=> 'Categories/#; s#=> 'chatbot/#=> 'Chatbot/#; s#=> 'faq/#=> 'Faq/#; \
  s#=> 'internal/#=> 'Internal/#; s#=> 'knowledge/#=> 'Knowledge/#; s#=> 'orders/#=> 'Orders/#; \
  s#=> 'outfit/#=> 'Outfit/#; s#=> 'products/#=> 'Products/#; s#=> 'size-guide/#=> 'SizeGuide/#" src/Http/routes.php
grep -c "=> '[A-Z]" src/Http/routes.php
```
Expected: số dòng bằng số route (mọi handler đều bắt đầu bằng chữ hoa).

**Step 5: Sửa `public/api/index.php`**
- Hai dòng require đầu file → 
  ```php
  require_once dirname(__DIR__, 2) . '/src/Http/helpers.php';
  require_once dirname(__DIR__, 2) . '/src/Http/middleware.php';
  ```
- Thay chỗ khai báo bảng route bằng `$routes = require dirname(__DIR__, 2) . '/src/Http/routes.php';`
- `$handlerFile = __DIR__ . '/controllers/' . $matched;` → `$handlerFile = dirname(__DIR__, 2) . '/src/Http/Controllers/' . $matched;`

**Step 6: Controller không tự nạp helpers nữa**

```bash
grep -rln "require_once __DIR__ . '/../../config.php';\|require_once __DIR__ . '/../../middleware.php';" src/Http/Controllers \
  | xargs sed -i "/require_once __DIR__ \. '\/\.\.\/\.\.\/\(config\|middleware\)\.php';/d"
grep -rn "require\|include" src/Http/Controllers
```
Expected: lệnh `grep` cuối không còn `require`/`include` trỏ tới `config.php`/`middleware.php`. Front controller đã nạp chúng ở global scope trước khi dispatch.

**Step 7: Shim tạm cho webroot cũ.** Webroot vẫn là gốc repo tới Task 16, nên tạo `api/index.php`:

```php
<?php
// Temporary shim until the web root moves to public/ (Task 16).
require dirname(__DIR__) . '/public/api/index.php';
```

**Step 8: Kiểm tra**

```bash
for f in $(git ls-files 'src/Http/*.php' 'public/*.php' api/index.php); do scripts/dev/php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
```
Expected: không lint fail; Unit `OK`; smoke toàn `ok`. Riêng Dockerfile phải chép thêm `public/`: thêm `COPY --chown=www-data:www-data public/ public/` cạnh dòng `COPY ... src/ src/` (Task 16 sẽ viết lại hoàn chỉnh).

**Step 9: Commit**

```bash
git add -A
git commit -m "refactor(http): routes, helpers and controllers under src/Http; front controller in public/api"
```

### Task 16: Storefront + admin + asset vào `public/`, template vào `templates/`, webroot = `public/`

**Step 1: Dời**

```bash
mkdir -p public/assets templates
git mv add_to_cart.php cart.php checkout.php index.php login.php logout.php order_details.php \
  process_checkout.php product.php profile.php register.php submit_review.php update_cart.php public/
git mv admin public/admin
git mv includes templates/partials
git mv css public/assets/css
git mv images public/assets/images
git rm -q api/index.php docker/apache.conf
```
(`docker/apache.conf` không được Dockerfile dùng, vì ảnh chạy nginx + php-fpm.)

**Step 2: Đường dẫn PHP trong trang**

```bash
# storefront pages: config/db.php -> src/bootstrap.php ; includes/ -> templates/partials/
sed -i "s#require_once 'config/db.php';#require_once __DIR__ . '/../src/bootstrap.php';#; \
        s#include 'includes/\([a-z]*\)\.php';#include __DIR__ . '/../templates/partials/\1.php';#" public/*.php
# admin pages
sed -i "s#require_once '\.\./config/db\.php';#require_once __DIR__ . '/../../src/bootstrap.php';#; \
        s#require_once __DIR__ \. '/\.\./config/db\.php';#require_once __DIR__ . '/../../src/bootstrap.php';#" public/admin/*.php
# partials
sed -i "s#require_once 'config/db.php';#require_once __DIR__ . '/../../src/bootstrap.php';#" templates/partials/header.php
sed -i "s#include 'chatbox.php';#include __DIR__ . '/chatbox.php';#" templates/partials/footer.php
grep -rn "require\|include" public templates | grep -v "__DIR__\|dirname("
```
Expected: lệnh `grep` cuối không trả về dòng nào.

**Step 3: Đường dẫn asset (URL `/css` → `/assets/css`, `/images` → `/assets/images`)**

```bash
grep -rn "images/\|css/" public templates src/Http/Controllers src/Chatbot | grep -v "assets/"
```
Sửa từng kết quả:
- URL trong HTML (`src="images/..."`, `href="css/style.css"`, `../images/`, `/images/`) → `/assets/images/...`, `/assets/css/style.css`.
- Đường dẫn ghi file upload trong `public/admin/*.php` (ví dụ `'../images/'`) → `__DIR__ . '/../assets/images/'`.
- `src/Http/Controllers/Admin/product_delete.php:16-17`: `__DIR__ . '/../../images/'` → `dirname(__DIR__, 4) . '/public/assets/images/'`.
- `src/Chatbot/Pipeline/EvidenceNormalizer.php` `productImageUrl()`: `'/images/'` → `'/assets/images/'`, `str_starts_with($path, 'images/')` → `str_starts_with($path, 'assets/images/')`.

Chạy lại lệnh `grep`; mọi kết quả còn lại phải là text không phải đường dẫn.

**Step 4: Webroot**
- `Dockerfile`: `NGINX_WEBROOT=/var/www/html` → `NGINX_WEBROOT=/var/www/html/public`. Khối COPY runtime thay bằng:

```dockerfile
COPY --from=php-deps --chown=www-data:www-data /build/vendor/ vendor/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data src/ src/
COPY --chown=www-data:www-data templates/ templates/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data sql/ sql/
COPY --chown=www-data:www-data knowledge/ knowledge/
COPY --chown=www-data:www-data scripts/run_database_migrations.php scripts/ingest_knowledge.php \
     scripts/publish_langfuse_trace_outbox.php scripts/ensure_admin_user.php scripts/
```
(xoá các dòng `COPY` cũ cho `api/ includes/ css/ images/ admin/ *.php`).
- `docker/app-nginx-api.conf`: giữ `$document_root/api/index.php` (document root giờ là `public/`).
- `docker/nginx/default.conf` (edge): `grep -n "images\|css" docker/nginx/default.conf`; nếu có location cache asset theo `/images`, `/css` thì đổi sang `/assets/`.

**Step 5: Smoke**

Trong `scripts/dev/smoke.sh` đổi `/css/style.css` → `/assets/css/style.css`, `/images/at_basic_01.jpg` → `/assets/images/at_basic_01.jpg`, và thêm các dòng chứng minh mã nguồn không còn lộ ra web:

```bash
check 404 GET /sql/shop_db.sql
check 404 GET /config/db.php
check 404 GET /src/bootstrap.php
check 404 GET /scripts/run_database_migrations.php
check 404 GET /templates/partials/header.php
```

```bash
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
```
Expected: toàn `ok`. Tiếp theo mở trình duyệt vào `/`, `/product.php?id=50`, `/admin/login.php`, kiểm tra ảnh, CSS và widget chat hiển thị đúng. Bước này người làm phải tự xem bằng mắt và ghi lại kết quả vào mô tả PR.

**Step 6: Commit**

```bash
git add -A
git commit -m "refactor(web): public/ is the only web root; templates and assets reorganised"
```

### Task 17: `sql/` → `database/`, cập nhật mọi tham chiếu

**Step 1: Dời**

```bash
mkdir -p database
git mv sql/shop_db.sql database/schema.sql
git mv sql/migrations database/migrations
```

**Step 2: Tham chiếu**

```bash
grep -rn "sql/shop_db.sql\|sql/migrations\|'sql/'\| sql/" --include=* \
  Dockerfile docker-compose.yml .github scripts src config tests .dockerignore phpunit.xml composer.json
```
Sửa từng dòng:
- `docker-compose.yml`: `./sql/shop_db.sql:/docker-entrypoint-initdb.d/01-shop_db.sql:ro` → `./database/schema.sql:/docker-entrypoint-initdb.d/01-shop_db.sql:ro`
- `Dockerfile`: `COPY ... sql/ sql/` → `COPY --chown=www-data:www-data database/ database/`
- `.github/workflows/ci.yml` (job integration): `sql/shop_db.sql` → `database/schema.sql`
- `scripts/run_database_migrations.php`: `'/sql/migrations'` → `'/database/migrations'`
- `scripts/dev/test-db.sh`: đã tự chọn `database/schema.sql` nếu có, không cần sửa.

**Step 3: Kiểm tra**

```bash
docker compose config --quiet
scripts/dev/test-db.sh && scripts/dev/test-integration.sh
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
```
Expected: compose hợp lệ; migration báo áp dụng đủ trên DB mới; integration `OK`; smoke toàn `ok`.

**Step 4: Commit**

```bash
git add -A
git commit -m "refactor(db): sql/ becomes database/{schema.sql,migrations}"
```

### Task 18: `ToolRegistry` thành gateway mỏng, logic về đúng service

> Spec §4.3 nói tách `ToolRegistry` thành `*ToolHandler`. Trong code đã có sẵn `ToolApplicationService` làm bộ phân phối, cùng các service `ProductService`/`SizeService`/`OrderService`/`KnowledgeService`. Hiện các service này lại gọi ngược vào `ToolRegistry` ("transitional"). Plan đảo chiều cho đúng: **logic chuyển vào service, `ToolRegistry` chỉ uỷ quyền**. Kết quả tương đương spec mà không thêm lớp handler trùng vai trò.

**Files:**
- Modify: `src/Shop/ProductService.php`, `src/Shop/SizeService.php`, `src/Chatbot/Tools/ToolRegistry.php`
- Create: `src/Shop/Catalog/ProductReranker.php`
- Test: `tests/Unit/ToolRegistryDelegationTest.php`

**Step 1: Viết test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ToolRegistry must be a thin gateway: same contracts, no own data access. */
final class ToolRegistryDelegationTest extends TestCase
{
    public function testRegistryHasNoPrivateToolImplementations(): void
    {
        $methods = array_map(
            fn(ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(ToolRegistry::class))->getMethods(ReflectionMethod::IS_PRIVATE)
        );
        $this->assertSame([], array_values(array_filter($methods, fn($n) => str_starts_with($n, 'execute'))));
    }

    public function testSizeSuggestionRunsWithoutRegistry(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE size_guides (id INTEGER PRIMARY KEY, product_id INTEGER, category_id INTEGER,
            size_name TEXT, height_from INTEGER, height_to INTEGER, weight_from INTEGER, weight_to INTEGER, description TEXT)');
        $pdo->exec("INSERT INTO size_guides VALUES (1,NULL,1,'M',160,170,55,65,'M'),(2,NULL,1,'L',165,175,60,75,'L')");

        $result = (new SizeService($pdo))->suggest(['height' => 168, 'weight' => 60, 'category_id' => 1]);

        $this->assertSame('M', $result['recommended']['size_name']);
    }
}
```

```bash
scripts/dev/composer dump-autoload
scripts/dev/php vendor/bin/phpunit tests/Unit/ToolRegistryDelegationTest.php
```
Expected: FAIL (`ToolRegistry` vẫn có các method `execute*` private).

**Step 2: Chuyển logic**
- **`SizeService`**: chép `suggestSizeDirect()`, `dedupeSizeRows()`, `isSqlite()` từ `ToolRegistry` sang làm method private; logic `executeSuggestSize()` (kể cả cache nếu có) thành `public function suggest(array $arguments): array`. Bỏ thuộc tính `$legacy`.
- **`ProductService`**: chép các method tìm kiếm (`executeSearchProducts` → `search`, `executeGetProductDetail` → `detail`, `executeGetCategories` → `categories`) cùng mọi helper private chúng dùng: `normalizeSearchArgs`, `extractSearchProductId`, `productDetailToCard`, `executeSearchProductsDirect`, `fetchFreshProductsByIds`, `fetchProductFreshById`, `fetchProductDetailStatic`, `attachProductSizes`, `absoluteUrl`, `isSqlite`, `fetchJson`, các hằng `RERANK_*` và `SEARCH_CACHE_VERSION`. Constructor nhận `PDO $pdo, ?int $userId = null`, tự tạo `CatalogVariantHydrator`. Bỏ `$legacy`.
- **`ProductReranker`** (class mới trong `src/Shop/Catalog/`): chuyển `applyRerank()`, `callReranker()`, `getRerankerUrl()` và hằng `RERANK_*` sang đây; `ProductService::search()` gọi `(new ProductReranker())->apply($query, $result)`.
- **Order và knowledge**: `executeGetOrderStatus` đã có tương đương ở `OrderService::status()`; dùng `diff` so sánh hai phần thân. Nếu khác (ví dụ `castOrder`), chép phần khác sang `OrderService`. `executeRetrieveKnowledge` → so với `KnowledgeService::retrieve()` theo cùng cách.
- **`ToolRegistry`** chỉ còn:

```php
<?php

/**
 * In-process chatbot tool gateway (CHATBOT_TOOL_TRANSPORT=internal|shadow).
 * Contracts come from ToolDefinitionCatalog; execution is delegated to the
 * same ToolApplicationService the MCP bridge uses, so both transports share
 * one implementation.
 */
class ToolRegistry implements ChatbotToolGateway
{
    private ToolApplicationService $tools;

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->tools = new ToolApplicationService($pdo, $userId);
    }

    public function getDefinitions(): array
    {
        return ToolDefinitionCatalog::chatbotDefinitions();
    }

    public function execute(string $toolName, array $arguments): array
    {
        return $this->tools->execute($toolName, $arguments);
    }
}
```

Nếu `ToolApplicationService::execute` ném `InvalidArgumentException("Unknown tool")` mà `ToolRegistry` cũ ném `RuntimeException`, kiểm tra caller bằng `grep -rn "Unknown tool" src tests`; nếu có test dựa vào loại exception thì giữ hành vi cũ bằng cách bắt và ném lại `RuntimeException`.

**Step 3: Kiểm tra**

```bash
scripts/dev/composer dump-autoload
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/test-integration.sh
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
wc -l src/Chatbot/Tools/ToolRegistry.php
```
Expected: mọi suite `OK`; smoke `ok`; `ToolRegistry.php` dưới 40 dòng.

**Step 4: Commit**

```bash
git add -A
git commit -m "refactor(tools): ToolRegistry delegates to shop services; reranker extracted"
```

### Task 19: Đồng bộ cấu hình công cụ

**Files:** `composer.json`, `phpstan.neon`, `phpunit.xml`, `.github/workflows/ci.yml`, `.dockerignore`

- `composer.json` script `stan`: `phpstan analyse --level=1 config/ src/`; script `lint`/`lint:fix`: thêm `--ignore=...,public/assets` cạnh danh sách hiện có.
- `phpstan.neon`: thay mọi path `api/` bằng `src/`.
- `phpunit.xml`: thêm `<source><include><directory>src</directory></include></source>` nếu file có khối `source`/`coverage` trỏ tới `api/`.
- `ci.yml`: bước PHPStan → `vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress`; bước PHP lint: đổi danh sách thư mục quét sang `public src config scripts templates tests`.
- `.dockerignore`: thêm `scripts/dev`, `docs`, `tests`, `.github` nếu chưa có.

```bash
grep -rn "api/" composer.json phpstan.neon phpunit.xml .github/workflows/ci.yml .dockerignore
```
Expected: chỉ còn URL kiểu `/api/...` (route HTTP), không còn path thư mục `api/`.

Chạy **bộ cổng**, rồi commit:

```bash
git add -A
git commit -m "chore: point lint, static analysis and CI at the new layout"
```

---

# GIAI ĐOẠN 3: UC-A, phối set theo tag (spec §5)

> **Điều chỉnh có chủ đích so với spec §5.4:** khi hoà điểm, spec chọn "tổng giá thấp hơn". Plan dùng quy tắc sau:
> - **Có ngân sách:** chọn tổng **cao hơn**, để tận dụng ngân sách và tăng giá trị đơn, đúng mục tiêu thương mại.
> - **Không có ngân sách:** chọn tổng thấp hơn.
>
> Với dữ liệu thật, câu demo "800k đi phỏng vấn" khi đó ra set áo 61 + **quần tây 66** (790k), thay vì áo + chân váy 680k. Báo người dùng điểm này khi bàn giao.

### Task 20: Helper test SQLite dùng chung

**Files:**
- Create: `tests/Support/SqliteShop.php`
- Modify: `tests/bootstrap.php`

**Step 1: Tạo `tests/Support/SqliteShop.php`**

```php
<?php

declare(strict_types=1);

/** Minimal in-memory shop schema for hermetic unit tests (no MariaDB). */
final class SqliteShop
{
    public static function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, name TEXT, price REAL,
            stock INTEGER, description TEXT, image TEXT, style_tags TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE TABLE product_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, size_name TEXT)');
        $pdo->exec('CREATE TABLE size_guides (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, category_id INTEGER,
            size_name TEXT, height_from INTEGER, height_to INTEGER, weight_from INTEGER, weight_to INTEGER, description TEXT)');
        $pdo->exec('CREATE TABLE cart (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, product_id INTEGER,
            quantity INTEGER DEFAULT 1, size TEXT)');
        $pdo->exec('CREATE TABLE chat_session_memory (session_id INTEGER PRIMARY KEY, summary TEXT, slots TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $pdo;
    }

    /** @param list<string> $sizes */
    public static function product(
        PDO $pdo,
        int $id,
        int $categoryId,
        string $name,
        int $price,
        int $stock,
        string $tags,
        array $sizes = ['S', 'M', 'L', 'XL']
    ): void {
        $pdo->prepare('INSERT INTO products (id, category_id, name, price, stock, description, image, style_tags)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $categoryId, $name, $price, $stock, '', "p$id.jpg", $tags]);
        $insert = $pdo->prepare('INSERT INTO product_sizes (product_id, size_name) VALUES (?, ?)');
        foreach ($sizes as $size) {
            $insert->execute([$id, $size]);
        }
    }

    public static function sizeGuides(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT INTO size_guides (category_id, size_name, height_from, height_to, weight_from, weight_to)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([1, 2] as $category) {
            $insert->execute([$category, 'S', 155, 165, 45, 55]);
            $insert->execute([$category, 'M', 160, 170, 55, 65]);
            $insert->execute([$category, 'L', 165, 175, 60, 75]);
        }
        $insert->execute([3, 'M', 155, 165, 48, 58]);
        $insert->execute([3, 'L', 160, 170, 55, 68]);
    }
}
```

**Step 2:** thêm vào cuối `tests/bootstrap.php`: `require_once __DIR__ . '/Support/SqliteShop.php';`

**Step 3:** `scripts/dev/php vendor/bin/phpunit --testsuite=Unit` → `OK`. Commit:

```bash
git add tests/Support/SqliteShop.php tests/bootstrap.php
git commit -m "test: shared in-memory sqlite shop fixture"
```

### Task 21: `StyleTag`, bộ tag cố định

**Files:**
- Create: `src/Shop/Catalog/StyleTag.php`
- Test: `tests/Unit/StyleTagTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StyleTagTest extends TestCase
{
    public function testParseListKeepsOnlyKnownTagsUniqueAndAtMostThree(): void
    {
        $this->assertSame(['congso', 'phongvan', 'basic'], StyleTag::parseList(' congso,phongvan,,xyz,congso,basic,hendo'));
        $this->assertSame([], StyleTag::parseList(null));
    }

    public function testToColumnNormalisesFormInput(): void
    {
        $this->assertSame('dichoi,dibien', StyleTag::toColumn(['dichoi', 'bogus', 'dibien']));
    }

    public function testFromTextFindsTagsInOrderOfAppearance(): void
    {
        $this->assertSame(['mualanh', 'congso'], StyleTag::fromText('Trời lạnh mặc gì đi làm, có 1.5 triệu'));
        $this->assertSame(['phongvan'], StyleTag::fromText('Có 800k, cần đồ đi phỏng vấn, cao 1m70'));
        $this->assertSame(['thethao'], StyleTag::fromText('gợi ý set đồ tập gym'));
    }

    public function testFromTextIgnoresSubstringsInsideOtherWords(): void
    {
        $this->assertSame([], StyleTag::fromText('update giỏ hàng giúp tôi'));
        $this->assertSame([], StyleTag::fromText('áo thun dưới 300k'));
    }

    public function testLabelsAreVietnamese(): void
    {
        $this->assertSame('phỏng vấn', StyleTag::label('phongvan'));
        $this->assertCount(10, StyleTag::ALL);
    }
}
```

**Step 2:** `scripts/dev/php vendor/bin/phpunit tests/Unit/StyleTagTest.php` → FAIL `Class "StyleTag" not found`.

**Step 3: `src/Shop/Catalog/StyleTag.php`**

```php
<?php

/**
 * Closed vocabulary of occasion/style tags stored in products.style_tags
 * (comma-separated, 1-3 per product). The same list drives the chat parser,
 * the LLM enum, the admin form and the migration check.
 */
final class StyleTag
{
    public const ALL = [
        'congso', 'phongvan', 'dichoi', 'dibien', 'hendo',
        'dutiec', 'thethao', 'streetstyle', 'basic', 'mualanh',
    ];

    public const MAX_PER_PRODUCT = 3;

    /** Tags a single chat request may ask for. */
    public const MAX_PER_REQUEST = 2;

    private const LABELS = [
        'congso' => 'công sở',
        'phongvan' => 'phỏng vấn',
        'dichoi' => 'đi chơi',
        'dibien' => 'đi biển',
        'hendo' => 'hẹn hò',
        'dutiec' => 'dự tiệc',
        'thethao' => 'thể thao',
        'streetstyle' => 'streetstyle',
        'basic' => 'basic',
        'mualanh' => 'mùa lạnh',
    ];

    /**
     * Letter boundaries keep "date" out of "update". Unaccented "mua ..."
     * variants are omitted on purpose: "mua" also means "buy".
     */
    public const PATTERNS = [
        'phongvan' => '/(?<!\p{L})(phỏng\s+vấn|phong\s+van|interview)(?!\p{L})/u',
        'congso' => '/(?<!\p{L})(công\s+sở|cong\s+so|đi\s+làm|di\s+lam|văn\s+phòng|van\s+phong)(?!\p{L})/u',
        'dichoi' => '/(?<!\p{L})(đi\s+chơi|di\s+choi|dạo\s+phố|dao\s+pho|cà\s+phê|ca\s+phe|cafe)(?!\p{L})/u',
        'dibien' => '/(?<!\p{L})(đi\s+biển|di\s+bien|du\s+lịch|du\s+lich|mùa\s+hè|đi\s+bơi)(?!\p{L})/u',
        'hendo' => '/(?<!\p{L})(hẹn\s+hò|hen\s+ho|date)(?!\p{L})/u',
        'dutiec' => '/(?<!\p{L})(dự\s+tiệc|du\s+tiec|đi\s+tiệc|tiệc|đám\s+cưới|dam\s+cuoi|sự\s+kiện)(?!\p{L})/u',
        'thethao' => '/(?<!\p{L})(thể\s+thao|the\s+thao|tập\s+gym|tap\s+gym|gym|chạy\s+bộ|chay\s+bo)(?!\p{L})/u',
        'streetstyle' => '/(?<!\p{L})(streetstyle|street\s+style|đường\s+phố|duong\s+pho|cá\s+tính|ca\s+tinh)(?!\p{L})/u',
        'basic' => '/(?<!\p{L})(basic|tối\s+giản|toi\s+gian|đơn\s+giản|don\s+gian)(?!\p{L})/u',
        'mualanh' => '/(?<!\p{L})(mùa\s+đông|mùa\s+lạnh|trời\s+lạnh|troi\s+lanh|giữ\s+ấm|giu\s+am)(?!\p{L})/u',
    ];

    public static function isValid(mixed $tag): bool
    {
        return is_string($tag) && in_array($tag, self::ALL, true);
    }

    public static function label(string $tag): string
    {
        return self::LABELS[$tag] ?? $tag;
    }

    /** @return list<string> */
    public static function parseList(?string $csv): array
    {
        $tags = array_map('trim', explode(',', (string)$csv));
        $tags = array_values(array_unique(array_filter($tags, [self::class, 'isValid'])));
        return array_slice($tags, 0, self::MAX_PER_PRODUCT);
    }

    /** @param array<mixed> $tags admin form input */
    public static function toColumn(array $tags): string
    {
        return implode(',', self::parseList(implode(',', array_map('strval', $tags))));
    }

    /** @return list<string> tags mentioned in the text, in order of appearance */
    public static function fromText(string $text): array
    {
        $lower = mb_strtolower($text);
        $positions = [];
        foreach (self::PATTERNS as $tag => $pattern) {
            if (preg_match($pattern, $lower, $m, PREG_OFFSET_CAPTURE) === 1) {
                $positions[$tag] = (int)$m[0][1];
            }
        }
        asort($positions);
        return array_slice(array_keys($positions), 0, self::MAX_PER_REQUEST);
    }
}
```

**Step 4:** `scripts/dev/composer dump-autoload && scripts/dev/php vendor/bin/phpunit tests/Unit/StyleTagTest.php` → `OK (5 tests, ...)`.

**Step 5: Commit**

```bash
git add src/Shop/Catalog/StyleTag.php tests/Unit/StyleTagTest.php
git commit -m "feat(catalog): closed style tag vocabulary"
```

### Task 22: Migration `products.style_tags` + tag cho 50 sản phẩm

**Files:**
- Create: `database/migrations/2026_10_15_product_style_tags.sql`
- Test: `tests/Unit/ProductStyleTagsMigrationTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every seeded product gets 1-3 tags from the closed vocabulary. */
final class ProductStyleTagsMigrationTest extends TestCase
{
    private const FILE = ROOT_DIR . '/database/migrations/2026_10_15_product_style_tags.sql';

    public function testAddsColumnAndTagsEverySeededProduct(): void
    {
        $sql = (string)file_get_contents(self::FILE);
        $this->assertMatchesRegularExpression('/ALTER TABLE products ADD COLUMN style_tags VARCHAR\(100\) NOT NULL DEFAULT \'\'/', $sql);

        preg_match_all("/UPDATE products SET style_tags = '([a-z,]+)' WHERE id = (\d+);/", $sql, $m, PREG_SET_ORDER);
        $ids = array_map(fn($row) => (int)$row[2], $m);
        $this->assertSame(range(50, 99), $ids);

        foreach ($m as [, $csv, $id]) {
            $tags = explode(',', $csv);
            $this->assertSame(StyleTag::parseList($csv), $tags, "product $id has an invalid or duplicate tag");
            $this->assertGreaterThanOrEqual(1, count($tags), "product $id");
            $this->assertLessThanOrEqual(StyleTag::MAX_PER_PRODUCT, count($tags), "product $id");
        }
    }

    public function testEveryTagIsUsedAtLeastOnce(): void
    {
        $sql = (string)file_get_contents(self::FILE);
        foreach (StyleTag::ALL as $tag) {
            $this->assertMatchesRegularExpression("/'[a-z,]*\b$tag\b[a-z,]*'/", $sql, "unused tag $tag");
        }
    }
}
```

**Step 2:** chạy test → FAIL (file không tồn tại).

**Step 3: Tạo migration.** Tag do Claude gắn từ tên + mô tả sản phẩm (spec §5.1). Người dùng duyệt bảng này trong PR.

```sql
-- UC-A outfit builder: 1-3 occasion/style tags per product (StyleTag::ALL).
-- Tags were assigned once, offline, from each product's name and description;
-- change them with a new migration or the admin form, never at runtime.
ALTER TABLE products ADD COLUMN style_tags VARCHAR(100) NOT NULL DEFAULT '' AFTER description;

UPDATE products SET style_tags = 'basic,dichoi,dibien' WHERE id = 50;
UPDATE products SET style_tags = 'dibien,dichoi,hendo' WHERE id = 51;
UPDATE products SET style_tags = 'streetstyle,dichoi,mualanh' WHERE id = 52;
UPDATE products SET style_tags = 'congso,phongvan,mualanh' WHERE id = 53;
UPDATE products SET style_tags = 'thethao,dichoi' WHERE id = 54;
UPDATE products SET style_tags = 'streetstyle,mualanh,dichoi' WHERE id = 55;
UPDATE products SET style_tags = 'mualanh,hendo' WHERE id = 56;
UPDATE products SET style_tags = 'congso,phongvan,dutiec' WHERE id = 57;
UPDATE products SET style_tags = 'streetstyle,dichoi' WHERE id = 58;
UPDATE products SET style_tags = 'mualanh,streetstyle,hendo' WHERE id = 59;
UPDATE products SET style_tags = 'streetstyle,basic,dichoi' WHERE id = 60;
UPDATE products SET style_tags = 'congso,phongvan,basic' WHERE id = 61;
UPDATE products SET style_tags = 'thethao,streetstyle,mualanh' WHERE id = 62;
UPDATE products SET style_tags = 'dichoi,streetstyle' WHERE id = 63;
UPDATE products SET style_tags = 'mualanh,congso,hendo' WHERE id = 64;
UPDATE products SET style_tags = 'basic,dichoi,hendo' WHERE id = 65;
UPDATE products SET style_tags = 'congso,phongvan,dutiec' WHERE id = 66;
UPDATE products SET style_tags = 'dichoi,dibien' WHERE id = 67;
UPDATE products SET style_tags = 'thethao,streetstyle' WHERE id = 68;
UPDATE products SET style_tags = 'streetstyle,dichoi' WHERE id = 69;
UPDATE products SET style_tags = 'streetstyle' WHERE id = 70;
UPDATE products SET style_tags = 'streetstyle,dichoi' WHERE id = 71;
UPDATE products SET style_tags = 'dibien,dichoi' WHERE id = 72;
UPDATE products SET style_tags = 'streetstyle,dichoi' WHERE id = 73;
UPDATE products SET style_tags = 'thethao' WHERE id = 74;
UPDATE products SET style_tags = 'dibien,hendo' WHERE id = 75;
UPDATE products SET style_tags = 'hendo,dichoi' WHERE id = 76;
UPDATE products SET style_tags = 'basic,congso,dichoi' WHERE id = 77;
UPDATE products SET style_tags = 'dutiec,hendo' WHERE id = 78;
UPDATE products SET style_tags = 'streetstyle,dichoi,dibien' WHERE id = 79;
UPDATE products SET style_tags = 'dutiec' WHERE id = 80;
UPDATE products SET style_tags = 'hendo,dichoi' WHERE id = 81;
UPDATE products SET style_tags = 'congso,phongvan,hendo' WHERE id = 82;
UPDATE products SET style_tags = 'dutiec,hendo' WHERE id = 83;
UPDATE products SET style_tags = 'dutiec' WHERE id = 84;
UPDATE products SET style_tags = 'dichoi,streetstyle' WHERE id = 85;
UPDATE products SET style_tags = 'congso,dutiec' WHERE id = 86;
UPDATE products SET style_tags = 'dichoi,hendo' WHERE id = 87;
UPDATE products SET style_tags = 'congso,phongvan' WHERE id = 88;
UPDATE products SET style_tags = 'dutiec,hendo' WHERE id = 89;
UPDATE products SET style_tags = 'hendo,dutiec,dichoi' WHERE id = 90;
UPDATE products SET style_tags = 'streetstyle,thethao,dichoi' WHERE id = 91;
UPDATE products SET style_tags = 'dibien,dichoi' WHERE id = 92;
UPDATE products SET style_tags = 'congso,phongvan,basic' WHERE id = 93;
UPDATE products SET style_tags = 'hendo,dutiec,mualanh' WHERE id = 94;
UPDATE products SET style_tags = 'hendo,dutiec,basic' WHERE id = 95;
UPDATE products SET style_tags = 'dutiec,hendo' WHERE id = 96;
UPDATE products SET style_tags = 'congso,basic' WHERE id = 97;
UPDATE products SET style_tags = 'congso,phongvan,hendo' WHERE id = 98;
UPDATE products SET style_tags = 'dichoi,basic' WHERE id = 99;
```

**Step 4: Chạy test + áp dụng thật**

```bash
scripts/dev/php vendor/bin/phpunit tests/Unit/ProductStyleTagsMigrationTest.php
scripts/dev/test-db.sh
docker compose run --rm --no-deps app php scripts/run_database_migrations.php
```
Expected: test `OK`; `test-db.sh` liệt kê `2026_10_15_product_style_tags.sql`; lệnh cuối áp dụng migration lên DB dev `shop_db`.

**Step 5: Commit**

```bash
git add database/migrations/2026_10_15_product_style_tags.sql tests/Unit/ProductStyleTagsMigrationTest.php
git commit -m "feat(db): products.style_tags with curated tags for the seeded catalog"
```

### Task 23: `OutfitSlot` + `OutfitPalette`

**Files:**
- Create: `src/Chatbot/Outfit/OutfitSlot.php`, `src/Chatbot/Outfit/OutfitPalette.php`
- Test: `tests/Unit/OutfitSlotPaletteTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitSlotPaletteTest extends TestCase
{
    public function testSlotsFollowCategoryAndSkirtsAreBottoms(): void
    {
        $this->assertSame(OutfitSlot::TOP, OutfitSlot::resolve(1, 'Áo Thun Cotton Basic Trắng'));
        $this->assertSame(OutfitSlot::BOTTOM, OutfitSlot::resolve(2, 'Quần Tây Ống Đứng Xám'));
        $this->assertSame(OutfitSlot::BOTTOM, OutfitSlot::resolve(3, 'Chân Váy Pleat xếp ly Midi'));
        $this->assertSame(OutfitSlot::DRESS, OutfitSlot::resolve(3, 'Váy Suông Thun Gân Đen'));
        $this->assertSame(OutfitSlot::ACCESSORY, OutfitSlot::resolve(4, 'Thắt Lưng Da Bò Nguyên Tấm'));
        $this->assertNull(OutfitSlot::resolve(5, 'Giày'));
    }

    public function testStatementPiecesAreAccentColoursOrPrints(): void
    {
        $this->assertTrue(OutfitPalette::isStatement('Áo Polo Thể Thao Cao Cấp Đỏ'));
        $this->assertTrue(OutfitPalette::isStatement('Áo Sơ Mi Caro Đỏ Đen'));
        $this->assertTrue(OutfitPalette::isStatement('Quần Kaki Ống Rộng Màu Xanh Rêu'));
        $this->assertFalse(OutfitPalette::isStatement('Áo Vest Blazer Công Sở Xanh Navy'));
        $this->assertFalse(OutfitPalette::isStatement('Quần Jeans Slimfit Xanh Đậm'));
        $this->assertFalse(OutfitPalette::isStatement('Áo Dài Tay Cổ Tim'), '"Tim" (neckline) is not "tím"');
    }
}
```

**Step 2:** chạy → FAIL (class chưa có).

**Step 3: `src/Chatbot/Outfit/OutfitSlot.php`**

```php
<?php

/** Position a product takes in an outfit, derived from its category. */
final class OutfitSlot
{
    public const TOP = 'top';
    public const BOTTOM = 'bottom';
    public const DRESS = 'dress';
    public const ACCESSORY = 'accessory';

    /**
     * Category 3 mixes skirts (worn with a top) and one-piece dresses;
     * product_subcategories has no rows for it, so the name decides.
     */
    public static function resolve(int $categoryId, string $name): ?string
    {
        return match ($categoryId) {
            1 => self::TOP,
            2 => self::BOTTOM,
            3 => str_starts_with(mb_strtolower(trim($name)), 'chân váy') ? self::BOTTOM : self::DRESS,
            4 => self::ACCESSORY,
            default => null,
        };
    }
}
```

**`src/Chatbot/Outfit/OutfitPalette.php`**

```php
<?php

/**
 * Colour rule for outfits: a set may contain at most one "statement" piece
 * (accent colour or print). Neutrals, navy and dark denim pair with anything.
 * Matching is on the accented product name so "Cổ Tim" is not "tím".
 */
final class OutfitPalette
{
    private const NEUTRAL_OVERRIDES = '/xanh\s+(?:navy|đậm)/u';
    private const STATEMENT = '/(?<!\p{L})(đỏ|hồng|xanh|vàng|tím|cam|rêu|caro|họa\s+tiết|graphic|phối\s+màu)(?!\p{L})/u';

    public static function isStatement(string $name): bool
    {
        $lower = (string)preg_replace(self::NEUTRAL_OVERRIDES, ' ', mb_strtolower($name));
        return preg_match(self::STATEMENT, $lower) === 1;
    }
}
```

**Step 4:** `scripts/dev/composer dump-autoload && scripts/dev/php vendor/bin/phpunit tests/Unit/OutfitSlotPaletteTest.php` → `OK`.

**Step 5:** `git add src/Chatbot/Outfit tests/Unit/OutfitSlotPaletteTest.php && git commit -m "feat(outfit): slot and palette rules"`

### Task 24: Value object + `OutfitBuilder`

**Files:**
- Create: `src/Chatbot/Outfit/{OutfitItem,OutfitRequest,OutfitSet,OutfitResult,OutfitBuilder}.php`
- Test: `tests/Unit/OutfitBuilderTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitBuilderTest extends TestCase
{
    /** @param list<string> $tags @param list<string> $sizes */
    private function item(int $id, string $slot, int $price, array $tags, int $stock = 5, array $sizes = ['M'], bool $statement = false): OutfitItem
    {
        $category = [OutfitSlot::TOP => 1, OutfitSlot::BOTTOM => 2, OutfitSlot::DRESS => 3, OutfitSlot::ACCESSORY => 4][$slot];
        return new OutfitItem($id, "SP$id", $price, $stock, $category, $slot, $tags, $slot === OutfitSlot::ACCESSORY ? [] : $sizes, $statement);
    }

    /** @return list<int> */
    private function ids(OutfitSet $set): array
    {
        return array_map(fn(OutfitItem $i) => $i->id, $set->items);
    }

    private function interviewCatalog(): array
    {
        return [
            $this->item(61, OutfitSlot::TOP, 250000, ['congso', 'phongvan', 'basic']),
            $this->item(53, OutfitSlot::TOP, 415000, ['congso', 'phongvan', 'mualanh']),
            $this->item(66, OutfitSlot::BOTTOM, 540000, ['congso', 'phongvan', 'dutiec']),
            $this->item(88, OutfitSlot::BOTTOM, 430000, ['congso', 'phongvan']),
            $this->item(67, OutfitSlot::BOTTOM, 310000, ['dichoi', 'dibien']),
            $this->item(93, OutfitSlot::ACCESSORY, 520000, ['congso', 'phongvan', 'basic']),
        ];
    }

    public function testBudgetPrefersTheSetThatUsesMostOfTheBudget(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan'], 800000));

        $this->assertNull($result->reason);
        $this->assertSame([61, 66], $this->ids($result->sets[0]));
        $this->assertSame(790000, $result->sets[0]->total);
        $this->assertSame([61, 88], $this->ids($result->sets[1]));
        foreach ($result->sets as $set) {
            $this->assertLessThanOrEqual(800000, $set->total);
        }
    }

    public function testWithoutBudgetCheaperSetWinsTiesAndAccessoryIsAdded(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan']));

        $this->assertSame([61, 88, 93], $this->ids($result->sets[0]));
    }

    public function testBudgetTooLowReportsCheapestValidTotal(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan'], 300000));

        $this->assertSame([], $result->sets);
        $this->assertSame(OutfitResult::BUDGET_TOO_LOW, $result->reason);
        $this->assertSame(680000, $result->cheapestTotal);
    }

    public function testOutOfStockAndWrongSizeItemsAreSkipped(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], stock: 0),
            $this->item(2, OutfitSlot::TOP, 100000, ['dichoi'], sizes: ['S']),
            $this->item(3, OutfitSlot::TOP, 200000, ['dichoi'], sizes: ['M', 'L']),
            $this->item(4, OutfitSlot::BOTTOM, 200000, ['dichoi'], sizes: ['L']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi'], null, [1 => 'L', 2 => 'L']));

        $this->assertSame([3, 4], $this->ids($result->sets[0]));
        $this->assertCount(1, $result->sets);
    }

    public function testAtMostOneStatementPiecePerSet(): void
    {
        $catalog = [
            $this->item(1, OutfitSlot::TOP, 100000, ['dichoi'], statement: true),
            $this->item(2, OutfitSlot::BOTTOM, 100000, ['dichoi'], statement: true),
            $this->item(3, OutfitSlot::BOTTOM, 150000, ['dichoi']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dichoi']));

        $this->assertSame([1, 3], $this->ids($result->sets[0]));
        $this->assertCount(1, $result->sets);
    }

    public function testDressIsACompleteBase(): void
    {
        $catalog = [
            $this->item(80, OutfitSlot::DRESS, 700000, ['dutiec']),
            $this->item(96, OutfitSlot::ACCESSORY, 280000, ['dutiec', 'hendo']),
        ];
        $result = (new OutfitBuilder())->build($catalog, new OutfitRequest(['dutiec'], 1000000));

        $this->assertSame([80, 96], $this->ids($result->sets[0]));
        $this->assertSame(980000, $result->sets[0]->total);
    }

    public function testAnchorIsFixedInItsSlotAndLendsItsTags(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest([], 1000000, [], 53));

        $this->assertSame(['congso', 'phongvan', 'mualanh'], $result->tags);
        foreach ($result->sets as $set) {
            $this->assertContains(53, $this->ids($set));
        }
    }

    public function testUnavailableAnchorAndMissingTagsAreReported(): void
    {
        $catalog = [$this->item(50, OutfitSlot::TOP, 180000, ['basic'], stock: 0)];
        $builder = new OutfitBuilder();

        $this->assertSame(OutfitResult::ANCHOR_UNAVAILABLE, $builder->build($catalog, new OutfitRequest([], null, [], 50))->reason);
        $this->assertSame(OutfitResult::ANCHOR_UNAVAILABLE, $builder->build($catalog, new OutfitRequest([], null, [], 999))->reason);
        $this->assertSame(OutfitResult::MISSING_TAGS, $builder->build($catalog, new OutfitRequest([]))->reason);
    }

    public function testNoItemsForTagsIsReported(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['thethao']));

        $this->assertSame(OutfitResult::NO_ITEMS_FOR_TAGS, $result->reason);
    }

    public function testSecondSetDiffersInAtLeastOneMainItem(): void
    {
        $result = (new OutfitBuilder())->build($this->interviewCatalog(), new OutfitRequest(['phongvan']));

        $this->assertCount(2, $result->sets);
        $this->assertNotSame($result->sets[0]->mainIds(), $result->sets[1]->mainIds());
    }
}
```

**Step 2:** chạy → FAIL (class chưa có).

**Step 3: Value object**

`src/Chatbot/Outfit/OutfitItem.php`:

```php
<?php

/** Catalog product as the outfit builder sees it. Prices are whole VND. */
final readonly class OutfitItem
{
    /**
     * @param list<string> $tags
     * @param list<string> $sizes upper-case sizes in stock; [] = not sized
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $price,
        public int $stock,
        public int $categoryId,
        public string $slot,
        public array $tags,
        public array $sizes,
        public bool $statement,
        public string $image = '',
    ) {
    }

    /** Accessories and products without size data never block a size filter. */
    public function fitsSize(?string $size): bool
    {
        if ($size === null || $this->slot === OutfitSlot::ACCESSORY || $this->sizes === []) {
            return true;
        }
        return in_array(strtoupper($size), $this->sizes, true);
    }

    /** @return array<string,mixed> product shape consumed by EvidenceNormalizer */
    public function toProduct(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'stock' => $this->stock,
            'category_id' => $this->categoryId,
            'image' => $this->image,
            'available_sizes' => $this->sizes,
            'style_tags' => $this->tags,
        ];
    }
}
```

`src/Chatbot/Outfit/OutfitRequest.php`:

```php
<?php

final readonly class OutfitRequest
{
    /**
     * @param list<string> $tags requested StyleTag values (may be [] with an anchor)
     * @param array<int,string> $sizeByCategory category_id => size
     */
    public function __construct(
        public array $tags,
        public ?int $budget = null,
        public array $sizeByCategory = [],
        public ?int $anchorProductId = null,
    ) {
    }

    public function sizeFor(int $categoryId): ?string
    {
        return $this->sizeByCategory[$categoryId] ?? null;
    }
}
```

`src/Chatbot/Outfit/OutfitSet.php`:

```php
<?php

final readonly class OutfitSet
{
    /**
     * @param list<OutfitItem> $items
     * @param list<string> $matchedTags
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $score,
        public array $matchedTags,
    ) {
    }

    /** @return list<int> sorted ids of non-accessory items */
    public function mainIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            if ($item->slot !== OutfitSlot::ACCESSORY) $ids[] = $item->id;
        }
        sort($ids);
        return $ids;
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn(OutfitItem $item) => $item->id, $this->items);
    }
}
```

`src/Chatbot/Outfit/OutfitResult.php`:

```php
<?php

final readonly class OutfitResult
{
    public const MISSING_TAGS = 'missing_tags';
    public const ANCHOR_UNAVAILABLE = 'anchor_unavailable';
    public const NO_ITEMS_FOR_TAGS = 'no_items_for_tags';
    public const BUDGET_TOO_LOW = 'budget_too_low';
    public const NO_COMPATIBLE_SET = 'no_compatible_set';

    /**
     * @param list<OutfitSet> $sets best first, at most OutfitBuilder::MAX_SETS
     * @param list<string> $tags tags actually used (anchor tags when none asked)
     */
    public function __construct(
        public array $sets,
        public array $tags,
        public ?string $reason = null,
        public ?int $cheapestTotal = null,
    ) {
    }
}
```

**Step 4: `src/Chatbot/Outfit/OutfitBuilder.php`**

```php
<?php

/**
 * Deterministic outfit composer (spec §5.4). Bases are top+bottom or a
 * dress; one accessory is added when it fits the budget and palette.
 * The catalog is small (tens of items), so combinations are enumerated.
 */
final class OutfitBuilder
{
    public const MAX_COMBINATIONS = 5000;
    public const MAX_SETS = 2;

    /** @param list<OutfitItem> $catalog */
    public function build(array $catalog, OutfitRequest $request): OutfitResult
    {
        $tags = array_values(array_slice($request->tags, 0, StyleTag::MAX_PER_REQUEST));
        $anchor = null;
        if ($request->anchorProductId !== null) {
            foreach ($catalog as $item) {
                if ($item->id === $request->anchorProductId) $anchor = $item;
            }
            if ($anchor === null || $anchor->stock <= 0) {
                return new OutfitResult([], $tags, OutfitResult::ANCHOR_UNAVAILABLE);
            }
            if ($tags === []) $tags = $anchor->tags;
        }
        if ($tags === []) {
            return new OutfitResult([], [], OutfitResult::MISSING_TAGS);
        }

        $bySlot = [OutfitSlot::TOP => [], OutfitSlot::BOTTOM => [], OutfitSlot::DRESS => [], OutfitSlot::ACCESSORY => []];
        foreach ($catalog as $item) {
            if ($anchor !== null && $item->id === $anchor->id) continue;
            if ($this->eligible($item, $tags, $request)) $bySlot[$item->slot][] = $item;
        }
        $hasCandidates = array_filter($bySlot) !== [];
        $forcedAccessory = null;
        if ($anchor !== null) {
            if ($anchor->slot === OutfitSlot::ACCESSORY) {
                $forcedAccessory = $anchor;
            } else {
                $bySlot[$anchor->slot] = [$anchor];
                if ($anchor->slot === OutfitSlot::DRESS) {
                    $bySlot[OutfitSlot::TOP] = [];
                    $bySlot[OutfitSlot::BOTTOM] = [];
                } else {
                    $bySlot[OutfitSlot::DRESS] = [];
                }
            }
        }

        $sets = [];
        $cheapest = null;
        foreach ($this->bases($bySlot) as $base) {
            if ($forcedAccessory !== null) $base[] = $forcedAccessory;
            if (!$this->paletteOk($base)) continue;
            $total = $this->total($base);
            $cheapest = $cheapest === null ? $total : min($cheapest, $total);
            if ($request->budget !== null && $total > $request->budget) continue;
            if ($forcedAccessory === null) {
                $base = $this->withBestAccessory($base, $bySlot[OutfitSlot::ACCESSORY], $tags, $request->budget);
            }
            $sets[] = $this->set($base, $tags);
        }

        if ($sets === []) {
            if (!$hasCandidates && $anchor === null) {
                return new OutfitResult([], $tags, OutfitResult::NO_ITEMS_FOR_TAGS);
            }
            if ($request->budget !== null && $cheapest !== null && $cheapest > $request->budget) {
                return new OutfitResult([], $tags, OutfitResult::BUDGET_TOO_LOW, $cheapest);
            }
            return new OutfitResult([], $tags, OutfitResult::NO_COMPATIBLE_SET);
        }

        $preferHigherTotal = $request->budget !== null;
        usort($sets, function (OutfitSet $a, OutfitSet $b) use ($preferHigherTotal): int {
            return [$b->score, $preferHigherTotal ? $b->total : $a->total, $a->ids()]
                <=> [$a->score, $preferHigherTotal ? $a->total : $b->total, $b->ids()];
        });

        $picked = [$sets[0]];
        foreach ($sets as $set) {
            if (count($picked) >= self::MAX_SETS) break;
            if ($set->mainIds() !== $sets[0]->mainIds()) $picked[] = $set;
        }
        return new OutfitResult($picked, $tags);
    }

    /** @param list<string> $tags */
    private function eligible(OutfitItem $item, array $tags, OutfitRequest $request): bool
    {
        return $item->stock > 0
            && array_intersect($item->tags, $tags) !== []
            && $item->fitsSize($request->sizeFor($item->categoryId));
    }

    /**
     * @param array<string,list<OutfitItem>> $bySlot
     * @return iterable<list<OutfitItem>>
     */
    private function bases(array $bySlot): iterable
    {
        $count = 0;
        foreach ($bySlot[OutfitSlot::DRESS] as $dress) {
            if (++$count > self::MAX_COMBINATIONS) return;
            yield [$dress];
        }
        foreach ($bySlot[OutfitSlot::TOP] as $top) {
            foreach ($bySlot[OutfitSlot::BOTTOM] as $bottom) {
                if (++$count > self::MAX_COMBINATIONS) return;
                yield [$top, $bottom];
            }
        }
    }

    /**
     * @param list<OutfitItem> $base
     * @param list<OutfitItem> $accessories
     * @param list<string> $tags
     * @return list<OutfitItem>
     */
    private function withBestAccessory(array $base, array $accessories, array $tags, ?int $budget): array
    {
        $best = $base;
        $bestScore = $this->score($base, $tags);
        $bestPrice = PHP_INT_MAX;
        foreach ($accessories as $accessory) {
            $candidate = [...$base, $accessory];
            if (!$this->paletteOk($candidate)) continue;
            if ($budget !== null && $this->total($candidate) > $budget) continue;
            $score = $this->score($candidate, $tags);
            if ($score > $bestScore || ($score === $bestScore && $best !== $base && $accessory->price < $bestPrice)) {
                $best = $candidate;
                $bestScore = $score;
                $bestPrice = $accessory->price;
            }
        }
        return $best;
    }

    /** @param list<OutfitItem> $items */
    private function paletteOk(array $items): bool
    {
        return count(array_filter($items, fn(OutfitItem $item) => $item->statement)) <= 1;
    }

    /** @param list<OutfitItem> $items */
    private function total(array $items): int
    {
        return array_sum(array_map(fn(OutfitItem $item) => $item->price, $items));
    }

    /**
     * 10 per requested tag each item carries, 3 per tag shared by a pair of
     * items (cohesion), 1 per neutral item.
     *
     * @param list<OutfitItem> $items
     * @param list<string> $tags
     */
    private function score(array $items, array $tags): int
    {
        $score = 0;
        foreach ($items as $i => $item) {
            $score += 10 * count(array_intersect($item->tags, $tags));
            if (!$item->statement) $score += 1;
            for ($j = $i + 1; $j < count($items); $j++) {
                $score += 3 * count(array_intersect($item->tags, $items[$j]->tags));
            }
        }
        return $score;
    }

    /**
     * @param list<OutfitItem> $items
     * @param list<string> $tags
     */
    private function set(array $items, array $tags): OutfitSet
    {
        $carried = array_merge(...array_map(fn(OutfitItem $item) => $item->tags, $items));
        $matched = array_values(array_filter($tags, fn(string $tag) => in_array($tag, $carried, true)));
        return new OutfitSet($items, $this->total($items), $this->score($items, $tags), $matched);
    }
}
```

**Step 5:** `scripts/dev/composer dump-autoload && scripts/dev/php vendor/bin/phpunit tests/Unit/OutfitBuilderTest.php`
Expected: `OK (10 tests, ...)`.

Nếu `testWithoutBudgetCheaperSetWinsTiesAndAccessoryIsAdded` fail, tính tay để xác nhận. 61+88+93 có điểm (10+1)+(10+1)+(10+1) + 3×(2 chung giữa 61-88 + 3 chung giữa 61-93 + 2 chung giữa 88-93) = 33 + 21 = 54, và 61+66+93 cũng 54. Không có ngân sách thì chọn tổng thấp hơn, tức 61+88+93. Sửa code, không sửa test.

**Step 6:** `git add src/Chatbot/Outfit tests/Unit/OutfitBuilderTest.php && git commit -m "feat(outfit): deterministic outfit builder"`

### Task 25: `OutfitCatalogRepository`

**Files:**
- Create: `src/Chatbot/Outfit/OutfitCatalogRepository.php`
- Test: `tests/Unit/OutfitCatalogRepositoryTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitCatalogRepositoryTest extends TestCase
{
    public function testLoadsSlottedItemsWithTagsSizesAndPalette(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 61, 1, 'Áo Dài Tay Cổ Tim', 250000, 10, 'congso,phongvan,basic');
        SqliteShop::product($pdo, 82, 3, 'Chân Váy Pleat xếp ly Midi', 470000, 9, 'congso,phongvan,hendo', ['M', 'L']);
        SqliteShop::product($pdo, 54, 1, 'Áo Polo Thể Thao Cao Cấp Đỏ', 290000, 3, 'thethao,bogus');
        SqliteShop::product($pdo, 99, 4, 'Cột Tóc Scrunchies Lụa', 45000, 20, 'dichoi,basic', []);
        SqliteShop::product($pdo, 120, 5, 'Giày', 500000, 1, 'dichoi');

        $items = [];
        foreach ((new OutfitCatalogRepository($pdo))->all() as $item) $items[$item->id] = $item;

        $this->assertSame([54, 61, 82, 99], array_keys($items));
        $this->assertSame(OutfitSlot::BOTTOM, $items[82]->slot);
        $this->assertSame(['L', 'M'], $items[82]->sizes);
        $this->assertSame(['thethao'], $items[54]->tags);
        $this->assertTrue($items[54]->statement);
        $this->assertFalse($items[61]->statement);
        $this->assertSame([], $items[99]->sizes);
        $this->assertSame(250000, $items[61]->price);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `src/Chatbot/Outfit/OutfitCatalogRepository.php`**

```php
<?php

/** Reads the private catalog in the shape OutfitBuilder needs. */
final class OutfitCatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<OutfitItem> every product that can occupy an outfit slot */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, category_id, name, price, stock, image, style_tags FROM products ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $sizes = $this->sizesByProduct();
        $items = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $slot = OutfitSlot::resolve((int)$row['category_id'], $name);
            if ($slot === null) continue;
            $id = (int)$row['id'];
            $items[] = new OutfitItem(
                $id,
                $name,
                (int)round((float)$row['price']),
                (int)$row['stock'],
                (int)$row['category_id'],
                $slot,
                StyleTag::parseList((string)($row['style_tags'] ?? '')),
                $sizes[$id] ?? [],
                OutfitPalette::isStatement($name),
                (string)($row['image'] ?? '')
            );
        }
        return $items;
    }

    /** @return array<int,list<string>> legacy product_sizes plus active in-stock variant sizes */
    private function sizesByProduct(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT product_id, size_name FROM product_sizes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['product_id']][] = strtoupper(trim((string)$row['size_name']));
        }
        try {
            $variants = $this->pdo->query(
                'SELECT product_id, size FROM product_variants
                 WHERE is_active = 1 AND size IS NOT NULL AND (stock IS NULL OR stock > 0)'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $variants = []; // product_variants is optional (catalog backfill not run)
        }
        foreach ($variants as $row) {
            $map[(int)$row['product_id']][] = strtoupper(trim((string)$row['size']));
        }
        foreach ($map as $id => $sizes) {
            $sizes = array_values(array_unique(array_filter($sizes)));
            sort($sizes);
            $map[$id] = $sizes;
        }
        return $map;
    }
}
```

**Step 4:** dump-autoload + chạy test → `OK`.

**Step 5:** `git add src/Chatbot/Outfit/OutfitCatalogRepository.php tests/Unit/OutfitCatalogRepositoryTest.php && git commit -m "feat(outfit): catalog repository with tags, sizes and palette"`

### Task 26: `OutfitService` + tool `build_outfit` (PHP + MCP)

**Files:**
- Create: `src/Chatbot/Outfit/OutfitService.php`
- Modify: `src/Chatbot/Tools/ToolApplicationService.php`, `src/Chatbot/Tools/ToolDefinitionCatalog.php`
- Modify: `mcp-server/src/tools.ts`, `mcp-server/tests/contract.test.ts`
- Test: `tests/Unit/OutfitServiceTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::sizeGuides($this->pdo);
        SqliteShop::product($this->pdo, 61, 1, 'Áo Dài Tay Cổ Tim', 250000, 10, 'congso,phongvan,basic');
        SqliteShop::product($this->pdo, 66, 2, 'Quần Tây Ống Đứng Xám', 540000, 11, 'congso,phongvan,dutiec', ['S']);
        SqliteShop::product($this->pdo, 88, 3, 'Chân Váy Chữ A Công Sở Vạt Xẻ', 430000, 8, 'congso,phongvan');
    }

    public function testToolResultCarriesOutfitsProductsAndResolvedSizes(): void
    {
        $result = (new OutfitService($this->pdo))->build([
            'style_tags' => ['phongvan', 'not-a-tag'],
            'budget' => 800000,
            'height' => 170,
            'weight' => 65,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([1 => 'M', 2 => 'M', 3 => 'L'], $result['size_by_category']);
        // 66 only has size S, so the M-sized shopper gets the skirt set.
        $this->assertSame([61, 88], $result['outfits'][0]['product_ids']);
        $this->assertSame(680000, $result['outfits'][0]['total_price']);
        $this->assertSame(1, $result['products'][0]['outfit_index']);
        $this->assertSame(['phongvan'], $result['style_tags']);
    }

    public function testExplicitSizeOverridesMeasurementsAndReasonIsExposed(): void
    {
        $result = (new OutfitService($this->pdo))->build(['style_tags' => ['phongvan'], 'budget' => 300000, 'size' => 'm']);

        $this->assertSame('no_outfit', $result['status']);
        $this->assertSame(OutfitResult::BUDGET_TOO_LOW, $result['reason']);
        $this->assertSame(680000, $result['cheapest_total']);
        $this->assertSame([], $result['products']);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `src/Chatbot/Outfit/OutfitService.php`**

```php
<?php

/** Tool `build_outfit`: validated arguments in, private-catalog sets out. */
final class OutfitService
{
    private const SIZED_CATEGORIES = [1, 2, 3];
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function build(array $arguments): array
    {
        $tags = array_values(array_filter((array)($arguments['style_tags'] ?? []), [StyleTag::class, 'isValid']));
        $budget = (int)($arguments['budget'] ?? 0) > 0 ? (int)$arguments['budget'] : null;
        $anchor = (int)($arguments['product_id'] ?? 0) > 0 ? (int)$arguments['product_id'] : null;
        $sizeByCategory = $this->sizeByCategory($arguments);

        $result = (new OutfitBuilder())->build(
            (new OutfitCatalogRepository($this->pdo))->all(),
            new OutfitRequest(array_slice($tags, 0, StyleTag::MAX_PER_REQUEST), $budget, $sizeByCategory, $anchor)
        );

        $outfits = [];
        $products = [];
        foreach ($result->sets as $index => $set) {
            $outfits[] = [
                'outfit_index' => $index + 1,
                'product_ids' => $set->ids(),
                'total_price' => $set->total,
                'matched_tags' => $set->matchedTags,
            ];
            foreach ($set->items as $item) {
                $products[] = $item->toProduct() + ['outfit_index' => $index + 1];
            }
        }

        return [
            'status' => $outfits === [] ? 'no_outfit' : 'ok',
            'reason' => $result->reason,
            'style_tags' => $result->tags,
            'budget' => $budget,
            'cheapest_total' => $result->cheapestTotal,
            'size_by_category' => $sizeByCategory,
            'outfits' => $outfits,
            'products' => $products,
        ];
    }

    /** @return array<int,string> */
    private function sizeByCategory(array $arguments): array
    {
        $size = strtoupper(trim((string)($arguments['size'] ?? '')));
        if (in_array($size, self::SIZES, true)) {
            return array_fill_keys(self::SIZED_CATEGORIES, $size);
        }
        $height = (int)($arguments['height'] ?? 0);
        $weight = (int)($arguments['weight'] ?? 0);
        if ($height <= 0 || $weight <= 0) {
            return [];
        }
        $sizes = new SizeService($this->pdo);
        $map = [];
        foreach (self::SIZED_CATEGORIES as $categoryId) {
            $recommended = $sizes->suggest(['height' => $height, 'weight' => $weight, 'category_id' => $categoryId])['recommended'] ?? null;
            if (is_array($recommended) && ($recommended['size_name'] ?? '') !== '') {
                $map[$categoryId] = strtoupper((string)$recommended['size_name']);
            }
        }
        return $map;
    }
}
```

**Step 4: Nối tool**

`ToolApplicationService`: lưu PDO (`private PDO $pdo;`, gán `$this->pdo = $pdo;` trong constructor), thêm arm:

```php
            'build_outfit' => (new OutfitService($this->pdo))->build($arguments),
```

`ToolDefinitionCatalog::chatbotDefinitions()` thêm sau `get_product_detail`:

```php
            self::definition('build_outfit', 'Ghép 1-2 set đồ còn hàng từ catalog shop theo tag dịp/phong cách, ngân sách và size.', [
                'type' => 'object',
                'properties' => [
                    'style_tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => StyleTag::ALL], 'maxItems' => 2],
                    'product_id' => ['type' => 'integer'],
                    'budget' => ['type' => 'integer'],
                    'size' => ['type' => 'string'],
                    'height' => ['type' => 'integer'],
                    'weight' => ['type' => 'integer'],
                ],
            ]),
```

`mcp-server/src/tools.ts`, thêm sau `get_product_detail` (danh sách tag giữ khớp `StyleTag::ALL`):

```ts
  const styleTags = ['congso', 'phongvan', 'dichoi', 'dibien', 'hendo', 'dutiec', 'thethao', 'streetstyle', 'basic', 'mualanh'] as const;

  server.registerTool('build_outfit', {
    title: 'Build outfit',
    description: 'Use this when the user wants a complete outfit for an occasion or style, optionally with a budget, size or anchor product. Returns in-stock shop products only.',
    inputSchema: {
      style_tags: z.array(z.enum(styleTags)).max(2).optional(),
      product_id: z.number().int().positive().optional(),
      budget: z.number().int().positive().optional(),
      size: z.string().max(5).optional(),
      height: z.number().int().min(80).max(230).optional(),
      weight: z.number().int().min(20).max(220).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('build_outfit', args, principal)));
```

Trong chuỗi `instructions` của `McpServer`, đổi `Outfit advice and external payment are unsupported.` thành `External payment is unsupported.`.

`mcp-server/tests/contract.test.ts`: thêm `'build_outfit'` vào mảng tên tool (giữ thứ tự alphabet: ngay đầu mảng, trước `'add_to_cart'`? Không: `'add_to_cart'` < `'build_outfit'`, nên đặt sau `'add_to_cart'`).

**Step 5: Kiểm tra**

```bash
scripts/dev/composer dump-autoload
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test && npm --prefix mcp-server run build
```
Expected: `OK`; MCP `fail 0`.

**Step 6:** `git add -A && git commit -m "feat(outfit): build_outfit tool over PHP and MCP"`

### Task 27: Intent `build_outfit` (parser, manifest, LLM parser)

**Files:**
- Modify: `src/Chatbot/Pipeline/DeterministicIntentParser.php`, `config/chatbot_tools.php`, `src/Chatbot/Pipeline/LlmInputParser.php`
- Modify (bỏ `unsupported_outfit`): `src/Chatbot/Pipeline/{EvidenceDecisionRouter,EvidenceExecutionLoop,TurnTierGate,PlanValidator,ResponseGenerator,LightweightEvidenceScorer}.php`
- Test: `tests/Unit/OutfitIntentTest.php`, `tests/Unit/ChatbotGuardrailTest.php`, `tests/Unit/ManifestPlannerTest.php`

**Step 1: Test (đỏ)** `tests/Unit/OutfitIntentTest.php`

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitIntentTest extends TestCase
{
    /** @return array<string,mixed> resolved field => value */
    private function fields(string $message): array
    {
        $resolved = (new DeterministicIntentParser())->parse($message, [])->toArray()['resolved_fields'];
        return array_map(fn($meta) => $meta['value'] ?? null, $resolved);
    }

    public function testOccasionRequestWithBudgetAndMeasurements(): void
    {
        $f = $this->fields('Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(['phongvan'], $f['style_tags']);
        $this->assertSame(800000, $f['max_price']);
        $this->assertSame(170, $f['height_cm']);
        $this->assertSame(65, $f['weight_kg']);
    }

    public function testGenericOutfitLanguageBecomesBuildOutfit(): void
    {
        $f = $this->fields('phối giúp tôi một set đồ đi chơi gồm áo và quần');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(['dichoi'], $f['style_tags']);
    }

    public function testAnchoredStylingKeepsProductId(): void
    {
        $f = $this->fields('Áo mã 61 phối với gì');
        $this->assertSame('build_outfit', $f['intent']);
        $this->assertSame(61, $f['product_id']);
    }

    public function testBudgetKeywordsNeedAMoneyUnit(): void
    {
        $this->assertSame(1000000, $this->fields('đi biển nên mặc gì, ngân sách 1 triệu')['max_price']);
        $this->assertSame(1500000, $this->fields('trời lạnh mặc gì đi làm, có 1.5 triệu')['max_price']);
        $this->assertArrayNotHasKey('max_price', $this->fields('có 2 cái áo thun trắng không'));
    }

    public function testCheckoutStillWinsOverOutfitLanguage(): void
    {
        $this->assertSame('unsupported_checkout', $this->fields('đặt hàng giúp mình set đồ đi chơi')['intent']);
    }

    public function testOccasionWithoutWearLanguageIsNotAnOutfit(): void
    {
        $this->assertNotSame('build_outfit', $this->fields('chính sách đổi trả hàng đi làm')['intent']);
    }
}
```

`ChatbotGuardrailTest`: đổi `testGenericOutfitRequestIsUnsupportedOutfit` thành:

```php
    public function testGenericOutfitRequestBuildsAnOutfitInsteadOfRefusing(): void
    {
        $this->assertSame('build_outfit', $this->intentOf('phối giúp tôi một set đồ đi chơi gồm áo và quần'));
    }
```

và xoá test `testAnchoredStylingRequestKeepsProductIdWithoutProviderIntent` (đã có ở `OutfitIntentTest`).
`ManifestPlannerTest::testGuardrailIntentsPlanNoTools`: đổi `['unsupported_outfit', 'unsupported_checkout']` thành `['unsupported_checkout']`.

**Step 2:** chạy `--filter 'OutfitIntentTest|ChatbotGuardrailTest|ManifestPlannerTest'` → FAIL.

**Step 3: `DeterministicIntentParser`**

(a) Trong `parse()`, thêm `$this->parseStyleTags($lower, $result, $matchedPatterns);` ngay sau `parsePrices(...)`.

(b) Method mới:

```php
    private function parseStyleTags(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        $tags = StyleTag::fromText($lower);
        if ($tags === []) return;
        $result->addResolvedField('style_tags', $tags);
        $result->addMatchedRule('style_tags');
        foreach ($tags as $tag) {
            $matchedPatterns[] = StyleTag::PATTERNS[$tag];
        }
    }
```

(c) Trong `priceCandidates()`, thêm trước `return $candidates;`:

```php
        // Outfit budgets: "có 800k", "ngân sách 1 triệu". A money unit is
        // required so "có 2 cái" never becomes a price.
        $budgetPattern = '/(?<![\p{L}])(?:ngân sách|ngan sach|budget|có|co)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|ngàn|triệu|trieu|tr)(?![\p{L}])/ui';
        if (preg_match_all($budgetPattern, $lower, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $unit = in_array(mb_strtolower($m[2][0]), ['tr'], true) ? 'triệu' : $m[2][0];
                $candidates[] = [
                    'field' => 'max_price',
                    'value' => $this->parsePrice($m[1][0], $unit),
                    'position' => $m[0][1],
                    'text' => $m[0][0],
                    'scope' => $this->priceScope($lower, (int)$m[0][1], strlen((string)$m[0][0])),
                ];
            }
        }
```

(d) `inferIntent()`: thay dòng `if ($this->isOutfit($lower)) return 'unsupported_outfit';` và `if ($this->isCheckout($lower)) return 'unsupported_checkout';` bằng (checkout luôn được xét trước):

```php
        if ($this->isCheckout($lower)) return 'unsupported_checkout';
        if ($this->isOutfit($lower) || (isset($fields['style_tags']) && $this->asksWhatToWear($lower))) {
            return 'build_outfit';
        }
```

(e) Method mới:

```php
    private function asksWhatToWear(string $text): bool {
        return (bool)preg_match(
            '/mặc gì|mac gi|nên mặc|nen mac|mặc sao|mac sao|mặc đi|mac di|đồ gì|do gi|cần đồ|can do|đồ đi|đồ để|'
            . 'trang phục|trang phuc|gợi ý (?:đồ|set|cách phối)|goi y (?:do|set)|chọn đồ|chon do/ui',
            $text
        );
    }
```

(f) **Sửa lỗi size giả** (phát hiện khi soạn eval). Regex `\b(xs|s|m|l|xl|xxl)\b` coi chữ có dấu là ký tự không phải chữ, nên "**m**ặc" thành size M và "**l**ạnh" thành size L. Trong `parseSizeAndMeasurements()`, đổi cả pattern lẫn chuỗi đưa vào `$matchedPatterns` thành:

```php
        $sizePattern = '/(?<![\p{L}\p{N}])(xs|s|m|l|xl|xxl)(?![\p{L}\p{N}])/ui';
        if (preg_match($sizePattern, $text, $m)) {
            $result->addResolvedField('size', strtoupper($m[1]));
            $result->addMatchedRule('size');
            $matchedPatterns[] = $sizePattern;
        }
```

(g) **Phối đồ không có dịp thì hỏi lại**, không rơi vào fallback. Trong `addMissingFields()` thêm:

```php
        if ($intent === 'build_outfit' && !isset($fields['style_tags']) && !isset($fields['product_id'])) {
            $result->addMissingField('style_tags');
        }
```

`EvidenceDecisionRouter` thấy slot còn thiếu nên chọn `ask_user`, và `response_type` giữ là `clarification`.

Thêm test vào `OutfitIntentTest`:

```php
    public function testAccentedWordsAreNotSizes(): void
    {
        $this->assertArrayNotHasKey('size', $this->fields('trời lạnh mặc gì đi làm'));
        $this->assertSame('M', $this->fields('đi hẹn hò mặc gì size M')['size']);
        $this->assertSame('L', $this->fields('áo thun size l còn không')['size']);
    }

    public function testOutfitWithoutOccasionAsksForIt(): void
    {
        $partial = (new DeterministicIntentParser())->parse('phối đồ giúp mình', [])->toArray();
        $this->assertContains('style_tags', $partial['missing_fields']);
    }
```

Chạy toàn bộ Unit sau khi sửa regex size. Nếu một test cũ hỏng vì dựa vào lỗi này, sửa test đó (ghi rõ trong commit) chứ không giữ lại lỗi.

**Step 4: Manifest `config/chatbot_tools.php`**
- Xoá entry `'unsupported_outfit' => [...]`.
- Thêm entry:

```php
        'build_outfit' => [
            'tools' => ['build_outfit'],
            'required_slots' => [],
            'empty_response' => 'clarification',
            'needs_auth' => false,
            'mutation' => false,
        ],
```

- Thêm `'style_tags',` vào `entity_fields`.

**Step 5: Bỏ `unsupported_outfit` ở mọi nơi còn lại**

```bash
grep -rn "unsupported_outfit" src config tests
```
Với mỗi dòng: xoá phần tử `'unsupported_outfit'` khỏi mảng/arm `match`. Trong `ResponseGenerator::generate()`, xoá khối `if ($primary === 'unsupported_outfit') { ... }`. Lệnh `grep` chạy lại phải rỗng.

**Step 6: `LlmInputParser`**
- `INTENTS`: thêm `'build_outfit',`.
- `tool()`: thêm `'style_tags'` vào `required` và `properties`: `'style_tags' => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => StyleTag::ALL]],`.
- `sanitize()`: thêm `'style_tags'` vào `$required`. Sau khối `foreach (['style', 'avoid'] ...)`, thêm:
  ```php
        $styleTags = array_values(array_filter((array)($arguments['style_tags'] ?? []), [StyleTag::class, 'isValid']));
        $result['style_tags'] = $styleTags === [] ? null : array_slice($styleTags, 0, StyleTag::MAX_PER_REQUEST);
  ```
- System prompt: thay câu `Câu phối đồ không có product_id là unsupported_outfit. Câu yêu cầu thêm giỏ/checkout/thanh toán hộ là unsupported_checkout.` bằng `Câu hỏi phối đồ hoặc nên mặc gì cho một dịp/phong cách là build_outfit; style_tags chỉ được chọn trong enum. Câu yêu cầu thêm giỏ/checkout/thanh toán hộ là unsupported_checkout.`

**Step 7:** `scripts/dev/php vendor/bin/phpunit --testsuite=Unit` → `OK`.

**Step 8:** `git add -A && git commit -m "feat(chatbot): build_outfit intent replaces the outfit refusal"`

### Task 28: Planner, validator, capability, evidence scorer

**Files:**
- Modify: `src/Chatbot/Pipeline/{ToolPlanner,PlanValidator,CapabilityRegistry,LightweightEvidenceScorer,EvidenceExecutionLoop}.php`
- Test: `tests/Unit/OutfitPlanningTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitPlanningTest extends TestCase
{
    public function testPlansBuildOutfitWithBudgetSizeAndMeasurements(): void
    {
        $plan = (new ToolPlanner([]))->plan([
            'primary_intent' => 'build_outfit',
            'entities' => ['style_tags' => ['phongvan', 'bogus'], 'max_price' => 800000, 'height' => 170, 'weight' => 65, 'size' => 'M'],
            'missing_slots' => [],
        ]);

        $call = $plan['batches'][0][0];
        $this->assertSame('build_outfit', $call['tool']);
        $this->assertSame(
            ['style_tags' => ['phongvan'], 'budget' => 800000, 'size' => 'M', 'height' => 170, 'weight' => 65],
            $call['args']
        );
    }

    public function testAnchorWithoutTagsIsEnoughButNeitherAsksForClarification(): void
    {
        $planner = new ToolPlanner([]);
        $anchored = $planner->plan(['primary_intent' => 'build_outfit', 'entities' => ['product_id' => 61], 'missing_slots' => []]);
        $this->assertSame(['product_id' => 61], $anchored['batches'][0][0]['args']);

        $empty = $planner->plan(['primary_intent' => 'build_outfit', 'entities' => [], 'missing_slots' => []]);
        $this->assertSame([], $empty['batches']);
        $this->assertSame('clarification', $empty['response_type']);
    }

    public function testValidatorRejectsProductSearchForOutfits(): void
    {
        $intent = ['primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['dichoi']], 'requested_fields' => [], 'original_query' => 'set đồ đi chơi'];
        $plan = ['batches' => [[['tool' => 'search_products', 'args' => ['search' => 'áo'], 'id' => 'x']]]];

        $errors = (new PlanValidator())->validate($plan, $intent);

        $this->assertContains('build_outfit_requires_build_outfit', $errors['errors'] ?? $errors);
    }
}
```

> Trước khi chạy, mở `PlanValidator` xem chữ ký `validate()` và dạng trả về (danh sách lỗi hay mảng có key `errors`), rồi chỉnh dòng `assertContains` cho khớp. Test `testValidatorRejectsProductSearchForOutfits` dùng `$errors['errors'] ?? $errors` để chịu được cả hai dạng.

**Step 2:** chạy → FAIL.

**Step 3: `ToolPlanner::buildCall()`** thêm case:

```php
            case 'build_outfit':
                if ($primary !== 'build_outfit') return null;
                $tags = array_values(array_filter((array)($entities['style_tags'] ?? []), [StyleTag::class, 'isValid']));
                if ($tags === [] && empty($entities['product_id'])) return null;
                $args = [];
                if ($tags !== []) $args['style_tags'] = array_slice($tags, 0, StyleTag::MAX_PER_REQUEST);
                if (!empty($entities['product_id'])) $args['product_id'] = (int)$entities['product_id'];
                if (!empty($entities['max_price'])) $args['budget'] = (int)$entities['max_price'];
                if (!empty($entities['size'])) $args['size'] = (string)$entities['size'];
                if (!empty($entities['height']) && !empty($entities['weight'])) {
                    $args['height'] = (int)$entities['height'];
                    $args['weight'] = (int)$entities['weight'];
                }
                return ['tool' => $tool, 'args' => $args, 'id' => 'outfit'];
```

**Step 4: `PlanValidator::validateUseCase()`** thêm:

```php
        if ($primary === 'build_outfit') {
            $canBuild = !empty($entities['style_tags']) || !empty($entities['product_id']);
            if ($canBuild && !$has('build_outfit')) $errors[] = 'build_outfit_requires_build_outfit';
            if (!$canBuild && $has('build_outfit')) $errors[] = 'build_outfit_without_context_must_ask';
            if ($has('search_products') || $has('get_product_detail')) $errors[] = 'build_outfit_must_use_outfit_tool';
            $args = $this->firstToolCall($plan, 'build_outfit')['args'] ?? [];
            if (!empty($entities['max_price']) && (int)($args['budget'] ?? 0) !== (int)$entities['max_price']) {
                $errors[] = 'build_outfit_missing_budget';
            }
        }
```

**Step 5: `CapabilityRegistry`**
- `relevantForPartial()`: thêm arm `'build_outfit' => ['build_outfit'],`.
- `knownMetadata()`: thêm

```php
            'build_outfit' => [
                'use_when' => ['Người dùng hỏi nên mặc gì / phối set cho một dịp hoặc phong cách, hoặc phối với một product_id cụ thể.'],
                'do_not_use_when' => ['Người dùng chỉ tìm một loại sản phẩm đơn lẻ.', 'Người dùng muốn thêm giỏ hoặc thanh toán.'],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => ['outfits' => ['type' => 'array'], 'products' => ['type' => 'array'], 'reason' => ['type' => ['string', 'null']]],
                ],
            ],
```

**Step 6: `LightweightEvidenceScorer`**
- `requiredFacts()`: thêm arm `'build_outfit' => ['outfit_result'],` và ngay sau khối `match`: `if ($primary === 'build_outfit') return $facts;`. Lý do: ngân sách áp lên cả set, không áp lên từng thẻ, và set có nhiều loại sản phẩm, nên các ràng buộc chung theo từng thẻ không áp dụng.
- `hasFact()`: thêm arm `'outfit_result' => $this->hasEvidenceType($evidence, 'outfit_sets') || $this->hasEvidenceType($evidence, 'outfit_unavailable'),`.

**Step 7: `EvidenceExecutionLoop`**
- `initialMissingEvidence()`: thêm nhánh `elseif ($primary === 'build_outfit') { $missing[] = 'outfit_result'; }`.
- `goal()`: thêm arm `'build_outfit' => 'compose_outfit_from_private_catalog',`.

**Step 8:** chạy Unit → `OK`. Commit:

```bash
git add -A && git commit -m "feat(chatbot): plan, validate and score build_outfit"
```

### Task 29: Normalizer, verifier, câu trả lời, chat-stream

**Files:**
- Modify: `src/Chatbot/Pipeline/{EvidenceNormalizer,ProductConstraintVerifier,ResponseGenerator}.php`
- Modify: `src/Http/Controllers/Chatbot/index.php`, `src/Http/Controllers/Chatbot/stream.php`, `mcp-server/src/chat-stream.ts`, `mcp-server/tests/chat-stream.test.ts`
- Test: `tests/Unit/OutfitResponseTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitResponseTest extends TestCase
{
    private function toolResult(array $outfits, array $products, ?string $reason = null, ?int $cheapest = null): array
    {
        return ['results' => ['outfit' => ['tool' => 'build_outfit', 'success' => true, 'result' => [
            'status' => $outfits === [] ? 'no_outfit' : 'ok', 'reason' => $reason, 'style_tags' => ['phongvan'],
            'budget' => 800000, 'cheapest_total' => $cheapest, 'outfits' => $outfits, 'products' => $products,
        ]]]];
    }

    private function intent(): array
    {
        return ['primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['phongvan'], 'max_price' => 800000]];
    }

    public function testAnswerListsEachSetWithTotalsAndKeepsOutfitIndexOnCards(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [61, 66], 'total_price' => 790000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'stock' => 10, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
            ]
        ));
        $normalized = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);
        $response = (new ResponseGenerator())->generate('800k đi phỏng vấn', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('Set 1, tổng 790.000đ', $response['message']);
        $this->assertStringContainsString('phỏng vấn', $response['message']);
        $this->assertSame(1, $response['cards'][0]['outfit_index']);
        $this->assertSame([61, 66], $response['outfits'][0]['product_ids']);
    }

    public function testVerifierDropsSetsOverBudgetOrOutOfStock(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult(
            [['outfit_index' => 1, 'product_ids' => [57, 66], 'total_price' => 1430000, 'matched_tags' => ['phongvan']]],
            [
                ['id' => 57, 'name' => 'Áo Vest', 'price' => 890000, 'stock' => 2, 'outfit_index' => 1],
                ['id' => 66, 'name' => 'Quần Tây', 'price' => 540000, 'stock' => 11, 'outfit_index' => 1],
            ]
        ));
        $verified = (new ProductConstraintVerifier())->verify($this->intent(), $normalized);

        $this->assertSame([], $verified['outfits']);
        $this->assertSame([], $verified['cards']);
    }

    public function testBudgetTooLowAnswerQuotesCheapestSet(): void
    {
        $normalized = (new EvidenceNormalizer())->normalize($this->intent(), $this->toolResult([], [], 'budget_too_low', 680000));
        $response = (new ResponseGenerator())->generate('300k', $this->intent(), $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('680.000đ', $response['message']);
        $this->assertSame([], $response['cards']);
    }

    public function testClarificationListsSupportedOccasions(): void
    {
        $intent = ['primary_intent' => 'build_outfit', 'entities' => [], 'missing_slots' => []];
        $response = (new ResponseGenerator())->generate('phối đồ giúp mình', $intent, ['cards' => [], 'evidence' => []], ['response_type' => 'clarification']);

        $this->assertSame('clarification', $response['response_type']);
        $this->assertStringContainsString('phỏng vấn', $response['message']);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `EvidenceNormalizer`**
- Trong `normalize()`: khai báo `$outfits = [];`; thêm nhánh `elseif ($tool === 'build_outfit') { $this->normalizeOutfits($result, $cards, $evidence, $outfits); }`; thêm `'outfits' => $outfits,` vào mảng trả về.
- `productCard()`: thêm key `'outfit_index' => isset($product['outfit_index']) ? (int)$product['outfit_index'] : null,`.
- Thêm public helper (dùng ở Task 34) và method normalize:

```php
    /** @return list<array<string,mixed>> */
    public function productCards(array $products): array {
        $cards = [];
        foreach ($products as $product) {
            if (!is_array($product)) continue;
            $card = $this->productCard($product);
            if ((int)$card['id'] > 0) $cards[] = $card;
        }
        return $cards;
    }

    private function normalizeOutfits(array $result, array &$cards, array &$evidence, array &$outfits): void {
        foreach ($this->productCards(is_array($result['products'] ?? null) ? $result['products'] : []) as $card) {
            $cards[] = $card;
            $this->addProductFacts($card, $evidence, 'outfit_builder');
        }
        $outfits = is_array($result['outfits'] ?? null) ? array_values($result['outfits']) : [];
        $evidence[] = [
            'source' => 'outfit_builder',
            'fact_type' => $outfits === [] ? 'outfit_unavailable' : 'outfit_sets',
            'value' => $outfits === [] ? (string)($result['reason'] ?? 'no_outfit') : $outfits,
            'style_tags' => is_array($result['style_tags'] ?? null) ? $result['style_tags'] : [],
            'budget' => isset($result['budget']) ? (int)$result['budget'] : null,
            'cheapest_total' => isset($result['cheapest_total']) ? (int)$result['cheapest_total'] : null,
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
    }
```

**Step 4: `ProductConstraintVerifier::verify()`**: dòng đầu thân hàm (sau `$primary = ...`) thêm `if ($primary === 'build_outfit') return $this->verifyOutfits($intent, $normalized);`, rồi thêm method:

```php
    /** A card survives only inside a set that is fully in stock and within budget. */
    private function verifyOutfits(array $intent, array $normalized): array {
        $budget = (int)($intent['entities']['max_price'] ?? 0);
        $cards = [];
        foreach (($normalized['cards'] ?? []) as $card) {
            if (is_array($card)) $cards[(int)($card['id'] ?? 0)] = $card;
        }
        $kept = [];
        $keptIds = [];
        foreach (($normalized['outfits'] ?? []) as $outfit) {
            $ids = array_map('intval', (array)($outfit['product_ids'] ?? []));
            $total = 0;
            $valid = $ids !== [];
            foreach ($ids as $id) {
                $card = $cards[$id] ?? null;
                if ($card === null || (int)($card['stock'] ?? 0) <= 0) { $valid = false; break; }
                $total += (int)round((float)($card['price'] ?? 0));
            }
            if (!$valid || ($budget > 0 && $total > $budget)) continue;
            $kept[] = $outfit;
            $keptIds = array_merge($keptIds, $ids);
        }
        $normalized['outfits'] = $kept;
        $normalized['cards'] = array_values(array_filter($cards, fn(array $card) => in_array((int)$card['id'], $keptIds, true)));
        return $normalized;
    }
```

**Step 5: `ResponseGenerator`**
- `generate()`: thêm arm `'build_outfit' => $this->outfitAnswer($intent, $normalized),`. Sau khi tạo `$response` cuối cùng, nếu `$primary === 'build_outfit'` thì gán `$response['outfits'] = $normalized['outfits'] ?? [];` (đổi `return $this->response(...)` cuối hàm thành gán vào biến rồi `return`).
- `clarificationAnswer()`: thêm ở đầu:

```php
        if (($intent['primary_intent'] ?? '') === 'build_outfit') {
            return 'Bạn muốn phối đồ cho dịp hoặc phong cách nào? Mình hỗ trợ: '
                . implode(', ', array_map([StyleTag::class, 'label'], StyleTag::ALL))
                . '. Bạn có thể kèm ngân sách và chiều cao, cân nặng để mình chọn đúng size.';
        }
```

- Method mới:

```php
    private function outfitAnswer(array $intent, array $normalized): string {
        $outfits = is_array($normalized['outfits'] ?? null) ? $normalized['outfits'] : [];
        $cards = [];
        foreach (($normalized['cards'] ?? []) as $card) {
            if (is_array($card)) $cards[(int)($card['id'] ?? 0)] = $card;
        }
        $fact = [];
        foreach (($normalized['evidence'] ?? []) as $item) {
            if (in_array($item['fact_type'] ?? '', ['outfit_sets', 'outfit_unavailable'], true)) $fact = $item;
        }
        $occasion = implode(', ', array_map([StyleTag::class, 'label'], (array)($fact['style_tags'] ?? [])));
        $budget = (int)($intent['entities']['max_price'] ?? 0);

        if ($outfits === []) {
            $reason = ($fact['fact_type'] ?? '') === 'outfit_unavailable' ? (string)($fact['value'] ?? '') : '';
            if ($reason === 'budget_too_low' && !empty($fact['cheapest_total'])) {
                return sprintf(
                    'Với ngân sách %s, mình chưa ghép được set %s còn hàng; set rẻ nhất hiện khoảng %s. Bạn có thể tăng ngân sách hoặc chọn dịp khác nhé.',
                    $this->money($budget), $occasion, $this->money((float)$fact['cheapest_total'])
                );
            }
            if ($reason === 'anchor_unavailable') {
                return sprintf(
                    'Sản phẩm mã %d hiện không còn hàng nên mình chưa phối được. Bạn chọn sản phẩm khác hoặc cho mình biết dịp bạn cần nhé.',
                    (int)($intent['entities']['product_id'] ?? 0)
                );
            }
            return 'Hiện shop chưa đủ sản phẩm còn hàng' . ($occasion !== '' ? " cho dịp $occasion" : '')
                . ' để ghép thành set. Bạn thử dịp hoặc phong cách khác nhé.';
        }

        $lines = [];
        foreach ($outfits as $i => $outfit) {
            $parts = [];
            foreach ((array)($outfit['product_ids'] ?? []) as $id) {
                $card = $cards[(int)$id] ?? null;
                if ($card === null) continue;
                $parts[] = sprintf('%s (mã %d) %s', (string)$card['name'], (int)$id, $this->money((float)$card['price']));
            }
            $lines[] = sprintf('Set %d, tổng %s: %s', $i + 1, $this->money((float)($outfit['total_price'] ?? 0)), implode(' + ', $parts));
        }
        return 'Mình gợi ý ' . count($outfits) . ' set'
            . ($occasion !== '' ? " cho dịp $occasion" : '')
            . ($budget > 0 ? ' trong ngân sách ' . $this->money($budget) : '') . '. '
            . implode('. ', $lines)
            . '. Bạn bấm vào thẻ sản phẩm để xem chi tiết và tự thêm vào giỏ nhé.';
    }
```

**Step 6: Response API + chat-stream**
- `src/Http/Controllers/Chatbot/index.php`: thêm `'outfits'` vào mảng key được trả về.
- `src/Http/Controllers/Chatbot/stream.php`: thêm `'outfits' => $result['outfits'] ?? [],` vào payload kết quả.
- `mcp-server/src/chat-stream.ts`: thêm `'outfit_index'` vào `safeProductFields`.
- `mcp-server/tests/chat-stream.test.ts`: thêm

```ts
test('cards keep outfit grouping but nothing else new', () => {
  assert.deepEqual(
    sanitizeProductCards([{ id: 61, name: 'Áo', outfit_index: 1, style_tags: ['phongvan'] }]),
    [{ id: 61, name: 'Áo', outfit_index: 1 }],
  );
});
```

**Step 7: Kiểm tra**

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test && npm --prefix mcp-server run build
```
Expected: `OK`; `fail 0`.

**Step 8:** `git add -A && git commit -m "feat(chatbot): grounded outfit answers with per-set verification"`

### Task 30: Admin chọn tag cho sản phẩm

**Files:** `public/admin/add_product.php`, `public/admin/edit_product.php`, `public/admin/manage_products.php`, `src/Http/Controllers/Admin/product_create.php`, `src/Http/Controllers/Admin/product_update.php`

**Step 1: Form.** Trong mỗi form thêm/sửa sản phẩm (3 trang admin), chèn trước nút submit (`$currentTags = []` cho form thêm; `StyleTag::parseList($product['style_tags'] ?? '')` cho form sửa):

```php
<fieldset style="margin: 10px 0;">
    <legend>Tag dịp / phong cách (tối đa <?= StyleTag::MAX_PER_PRODUCT ?>)</legend>
    <?php foreach (StyleTag::ALL as $tag): ?>
        <label style="margin-right: 12px;">
            <input type="checkbox" name="style_tags[]" value="<?= $tag ?>"
                <?= in_array($tag, $currentTags, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars(StyleTag::label($tag)) ?>
        </label>
    <?php endforeach; ?>
</fieldset>
```

**Step 2: Lưu.** Mỗi câu `INSERT INTO products` / `UPDATE products` (tìm bằng `grep -n "INSERT INTO products\|UPDATE products" public/admin src/Http/Controllers/Admin`):
- Trang HTML: thêm cột `style_tags` với giá trị `StyleTag::toColumn((array)($_POST['style_tags'] ?? []))`.
- API JSON (`product_create.php`, `product_update.php`): đọc `style_tags` (mảng) từ input JSON. Create: luôn ghi `StyleTag::toColumn(...)`. Update: chỉ thêm vào `$fields` khi request có key `style_tags`.

**Step 3: Kiểm tra thủ công**

```bash
docker compose up -d --build app && sleep 5 && scripts/dev/smoke.sh
```
Đăng nhập admin, sửa sản phẩm 70, tick `basic` rồi lưu. Kiểm tra:

```bash
set -a; . ./.env; set +a
docker compose exec -T db mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" shop_db -e "SELECT id, style_tags FROM products WHERE id = 70"
```
Expected: `streetstyle,basic` (hoặc đúng các ô đã tick). Hoàn tác về `streetstyle` sau khi thử.

**Step 4:** `git add -A && git commit -m "feat(admin): edit product style tags"`

### Task 31: Integration test UC-A trên DB thật

**Files:**
- Test: `tests/Integration/OutfitCatalogIntegrationTest.php`

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** UC-A against the real schema + migrations (shop_test). */
final class OutfitCatalogIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live catalog test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testEverySeededProductIsTagged(): void
    {
        $items = (new OutfitCatalogRepository($this->pdo))->all();
        $this->assertCount(50, $items);
        foreach ($items as $item) {
            $this->assertNotSame([], $item->tags, "product {$item->id} has no style tags");
        }
    }

    public function testInterviewDemoUsesTheBudget(): void
    {
        $result = (new OutfitService($this->pdo))->build(['style_tags' => ['phongvan'], 'budget' => 800000, 'height' => 170, 'weight' => 65]);

        $this->assertSame([61, 66], $result['outfits'][0]['product_ids']);
        $this->assertSame(790000, $result['outfits'][0]['total_price']);
    }
}
```

```bash
scripts/dev/test-db.sh && scripts/dev/test-integration.sh
git add tests/Integration/OutfitCatalogIntegrationTest.php
git commit -m "test(outfit): integration against seeded catalog"
```
Expected: `OK`.

---

# GIAI ĐOẠN 4: UC-C, gợi ý free ship (spec §6)

### Task 32: `FreeShippingPolicy` + `FreeShippingGapAdvisor`

**Files:**
- Create: `src/Chatbot/FreeShipping/FreeShippingPolicy.php`, `src/Chatbot/FreeShipping/FreeShippingGapAdvisor.php`
- Modify: `.env.example`, `docker-compose.yml` (env của `app`)
- Test: `tests/Unit/FreeShippingGapAdvisorTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingGapAdvisorTest extends TestCase
{
    private function item(int $id, int $price, array $tags = ['basic'], int $stock = 5, string $slot = OutfitSlot::TOP, array $sizes = ['M']): OutfitItem
    {
        $category = [OutfitSlot::TOP => 1, OutfitSlot::BOTTOM => 2, OutfitSlot::DRESS => 3, OutfitSlot::ACCESSORY => 4][$slot];
        return new OutfitItem($id, "SP$id", $price, $stock, $category, $slot, $tags, $slot === OutfitSlot::ACCESSORY ? [] : $sizes, false);
    }

    private function catalog(): array
    {
        return [
            $this->item(53, 415000, ['congso', 'phongvan', 'mualanh']),
            $this->item(99, 45000, ['dichoi', 'basic'], slot: OutfitSlot::ACCESSORY),
            $this->item(58, 210000, ['streetstyle', 'dichoi']),
            $this->item(61, 250000, ['congso', 'phongvan', 'basic']),
            $this->item(91, 150000, ['streetstyle'], stock: 0, slot: OutfitSlot::ACCESSORY),
            $this->item(96, 280000, ['dutiec', 'hendo'], slot: OutfitSlot::ACCESSORY),
            $this->item(77, 220000, ['basic'], sizes: ['S']),
        ];
    }

    public function testSuggestsClosestPriceAboveTheGap(): void
    {
        $advice = (new FreeShippingGapAdvisor())->advise(
            [['product_id' => 53, 'quantity' => 1, 'size' => 'M']],
            415000,
            $this->catalog(),
            new FreeShippingPolicy(500000, 200000)
        );

        $this->assertSame(FreeShippingGapAdvisor::GAP, $advice['status']);
        $this->assertSame(85000, $advice['gap']);
        // 99 is below the gap, 91 is out of stock, 77 lacks size M.
        $this->assertSame([58, 61, 96], array_map(fn(OutfitItem $i) => $i->id, $advice['suggestions']));
    }

    public function testEqualDistanceIsBrokenBySharedTagsWithTheCart(): void
    {
        $catalog = [
            $this->item(1, 300000, ['dutiec']),
            $this->item(2, 300000, ['congso']),
            $this->item(3, 200000, ['congso']),
        ];
        $advice = (new FreeShippingGapAdvisor())->advise(
            [['product_id' => 3, 'quantity' => 1, 'size' => 'M']],
            200000,
            $catalog,
            new FreeShippingPolicy(500000, 200000)
        );

        $this->assertSame([2, 1], array_map(fn(OutfitItem $i) => $i->id, $advice['suggestions']));
    }

    public function testQualifiedAndEmptyCarts(): void
    {
        $advisor = new FreeShippingGapAdvisor();
        $policy = new FreeShippingPolicy(500000, 200000);

        $this->assertSame(FreeShippingGapAdvisor::QUALIFIED, $advisor->advise([['product_id' => 53, 'quantity' => 2]], 830000, $this->catalog(), $policy)['status']);
        $this->assertSame(FreeShippingGapAdvisor::EMPTY_CART, $advisor->advise([], 0, $this->catalog(), $policy)['status']);
    }

    public function testPolicyReadsEnvironmentWithDefaults(): void
    {
        putenv('FREE_SHIPPING_THRESHOLD');
        putenv('FREESHIP_NUDGE_MAX_GAP=150000');
        $policy = FreeShippingPolicy::fromEnvironment();
        putenv('FREESHIP_NUDGE_MAX_GAP');

        $this->assertSame(500000, $policy->threshold);
        $this->assertSame(150000, $policy->nudgeMaxGap);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `src/Chatbot/FreeShipping/FreeShippingPolicy.php`**

```php
<?php

/** Free-shipping threshold from knowledge/policies.md, overridable per env. */
final readonly class FreeShippingPolicy
{
    public function __construct(
        public int $threshold = 500000,
        public int $nudgeMaxGap = 200000,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            max(0, self::intEnv('FREE_SHIPPING_THRESHOLD', 500000)),
            max(0, self::intEnv('FREESHIP_NUDGE_MAX_GAP', 200000))
        );
    }

    /** PHP-FPM may run with clear_env, so fall back to $_ENV/$_SERVER. */
    private static function intEnv(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return $value === '' ? $default : (int)$value;
    }
}
```

**`src/Chatbot/FreeShipping/FreeShippingGapAdvisor.php`**

```php
<?php

/**
 * Picks in-stock products that lift a cart over the free-shipping threshold
 * with the smallest overshoot (spec §6.2). Pure: callers supply cart + catalog.
 */
final class FreeShippingGapAdvisor
{
    public const QUALIFIED = 'qualified';
    public const EMPTY_CART = 'empty_cart';
    public const GAP = 'gap';

    /**
     * @param list<array{product_id:int,quantity:int,size?:?string}> $cartItems
     * @param list<OutfitItem> $catalog
     * @return array{status:string,threshold:int,subtotal:int,gap:int,suggestions:list<OutfitItem>}
     */
    public function advise(array $cartItems, int $subtotal, array $catalog, FreeShippingPolicy $policy, int $limit = 3): array
    {
        $gap = max(0, $policy->threshold - $subtotal);
        $base = ['threshold' => $policy->threshold, 'subtotal' => $subtotal, 'gap' => $gap, 'suggestions' => []];
        if ($cartItems === []) return ['status' => self::EMPTY_CART] + $base;
        if ($gap === 0) return ['status' => self::QUALIFIED] + $base;

        $byId = [];
        foreach ($catalog as $item) $byId[$item->id] = $item;
        $inCart = array_map(fn(array $row) => (int)$row['product_id'], $cartItems);
        $cartTags = [];
        foreach ($inCart as $id) {
            if (isset($byId[$id])) $cartTags = array_merge($cartTags, $byId[$id]->tags);
        }
        $size = $this->commonClothingSize($cartItems, $byId);

        $candidates = array_values(array_filter($catalog, fn(OutfitItem $item) => $item->stock > 0
            && !in_array($item->id, $inCart, true)
            && $item->price >= $gap
            && $item->fitsSize($size)));
        usort($candidates, fn(OutfitItem $a, OutfitItem $b) => [
            $a->price - $gap, -count(array_intersect($a->tags, $cartTags)), $a->id,
        ] <=> [
            $b->price - $gap, -count(array_intersect($b->tags, $cartTags)), $b->id,
        ]);

        return ['status' => self::GAP, 'suggestions' => array_slice($candidates, 0, max(1, $limit))] + $base;
    }

    /**
     * The shopper's size is only known when every sized cart item agrees.
     *
     * @param list<array{product_id:int,quantity:int,size?:?string}> $cartItems
     * @param array<int,OutfitItem> $byId
     */
    private function commonClothingSize(array $cartItems, array $byId): ?string
    {
        $sizes = [];
        foreach ($cartItems as $row) {
            $item = $byId[(int)$row['product_id']] ?? null;
            $size = strtoupper(trim((string)($row['size'] ?? '')));
            if ($item !== null && $item->slot !== OutfitSlot::ACCESSORY && $size !== '') $sizes[$size] = true;
        }
        return count($sizes) === 1 ? (string)array_key_first($sizes) : null;
    }
}
```

**Step 4: Cấu hình**
- `.env.example`: thêm
  ```env
  # UC-C free-shipping nudge (knowledge/policies.md: free ship from 500,000đ).
  FREE_SHIPPING_THRESHOLD=500000
  FREESHIP_NUDGE_MAX_GAP=200000
  ```
- `docker-compose.yml` `app.environment`: thêm `FREE_SHIPPING_THRESHOLD: ${FREE_SHIPPING_THRESHOLD:-500000}` và `FREESHIP_NUDGE_MAX_GAP: ${FREESHIP_NUDGE_MAX_GAP:-200000}`.

**Step 5:** dump-autoload, chạy test → `OK`; `docker compose config --quiet`.

**Step 6:** `git add -A && git commit -m "feat(freeship): policy and gap advisor"`

### Task 33: `FreeShippingService` + tool `suggest_freeship_items`

**Files:**
- Create: `src/Chatbot/FreeShipping/FreeShippingService.php`
- Modify: `src/Chatbot/Tools/{ToolApplicationService,ToolDefinitionCatalog}.php`, `mcp-server/src/tools.ts`, `mcp-server/tests/contract.test.ts`
- Test: `tests/Unit/FreeShippingServiceTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::product($this->pdo, 53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'congso,phongvan,mualanh');
        SqliteShop::product($this->pdo, 58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'streetstyle,dichoi');
        SqliteShop::product($this->pdo, 99, 4, 'Cột Tóc Scrunchies Lụa', 45000, 20, 'dichoi,basic', []);
    }

    public function testGuestGetsThresholdAndLoginRequirement(): void
    {
        $result = (new FreeShippingService($this->pdo, new FreeShippingPolicy()))->suggest(null, []);

        $this->assertSame('requires_login', $result['status']);
        $this->assertSame(500000, $result['threshold']);
        $this->assertSame([], $result['products']);
    }

    public function testCartBelowThresholdGetsSuggestionsAndAStableFingerprint(): void
    {
        $this->pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (7, 53, 1, 'M')");
        $service = new FreeShippingService($this->pdo, new FreeShippingPolicy());

        $first = $service->suggest(7, ['limit' => 1]);
        $second = $service->suggest(7, ['limit' => 1]);

        $this->assertSame('gap', $first['status']);
        $this->assertSame(85000, $first['gap']);
        $this->assertSame([58], array_column($first['products'], 'id'));
        $this->assertSame($first['cart_fingerprint'], $second['cart_fingerprint']);

        $this->pdo->exec('UPDATE cart SET quantity = 2 WHERE user_id = 7');
        $this->assertNotSame($first['cart_fingerprint'], $service->suggest(7, [])['cart_fingerprint']);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `src/Chatbot/FreeShipping/FreeShippingService.php`**

```php
<?php

/** Tool `suggest_freeship_items`: read-only, never mutates the cart. */
final class FreeShippingService
{
    public function __construct(private PDO $pdo, private ?FreeShippingPolicy $policy = null)
    {
    }

    /** @return array<string,mixed> */
    public function suggest(?int $userId, array $arguments): array
    {
        $policy = $this->policy ?? FreeShippingPolicy::fromEnvironment();
        if ($userId === null) {
            return ['status' => 'requires_login', 'threshold' => $policy->threshold, 'products' => []];
        }

        $cart = (new CartService($this->pdo))->list($userId);
        $items = array_map(fn(array $row) => [
            'product_id' => (int)$row['product_id'],
            'quantity' => (int)$row['quantity'],
            'size' => isset($row['size']) ? (string)$row['size'] : null,
        ], $cart['cart']);
        $advice = (new FreeShippingGapAdvisor())->advise(
            $items,
            (int)round((float)$cart['total']),
            (new OutfitCatalogRepository($this->pdo))->all(),
            $policy,
            max(1, min(5, (int)($arguments['limit'] ?? 3)))
        );

        return [
            'status' => $advice['status'],
            'threshold' => $advice['threshold'],
            'subtotal' => $advice['subtotal'],
            'gap' => $advice['gap'],
            'cart_fingerprint' => self::fingerprint($items),
            'products' => array_map(fn(OutfitItem $item) => $item->toProduct(), $advice['suggestions']),
        ];
    }

    /** @param list<array{product_id:int,quantity:int,size:?string}> $items */
    public static function fingerprint(array $items): string
    {
        $parts = array_map(fn(array $item) => $item['product_id'] . ':' . ($item['size'] ?? '') . ':' . $item['quantity'], $items);
        sort($parts);
        return sha1(implode('|', $parts));
    }
}
```

**Step 4: Nối tool**
- `ToolApplicationService::execute()` thêm arm (guest được phép, tool tự trả `requires_login`):
  ```php
            'suggest_freeship_items' => (new FreeShippingService($this->pdo))->suggest($this->userId, $arguments),
  ```
- `ToolDefinitionCatalog::chatbotDefinitions()` thêm:
  ```php
            self::definition('suggest_freeship_items', 'Kiểm tra giỏ hàng còn thiếu bao nhiêu để được miễn phí ship và gợi ý món còn hàng để bù. Không thêm vào giỏ.', [
                'type' => 'object',
                'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]],
            ]),
  ```
- `mcp-server/src/tools.ts` thêm:
  ```ts
  server.registerTool('suggest_freeship_items', {
    title: 'Suggest items to reach free shipping',
    description: 'Use this when the user asks how much more to buy, or what to add, to get free shipping. Read-only: it never changes the cart. Guests receive the threshold and a login requirement.',
    inputSchema: { limit: z.number().int().min(1).max(5).optional() }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('suggest_freeship_items', args, principal)));
  ```
- `mcp-server/tests/contract.test.ts`: thêm `'suggest_freeship_items'` vào mảng tên tool (sau `'search_products'`).

**Step 5:** dump-autoload; `scripts/dev/php vendor/bin/phpunit --testsuite=Unit`; `npm --prefix mcp-server test && npm --prefix mcp-server run build` → tất cả xanh.

**Step 6:** `git add -A && git commit -m "feat(freeship): suggest_freeship_items tool over PHP and MCP"`

### Task 34: Intent `freeship_gap` (hỏi thẳng)

**Files:**
- Modify: `src/Chatbot/Pipeline/{DeterministicIntentParser,ToolPlanner,PlanValidator,CapabilityRegistry,LightweightEvidenceScorer,EvidenceExecutionLoop,EvidenceNormalizer,ResponseGenerator,LlmInputParser}.php`, `config/chatbot_tools.php`, `src/Http/Controllers/Chatbot/{index,stream}.php`
- Test: `tests/Unit/FreeShippingIntentTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingIntentTest extends TestCase
{
    private function intentOf(string $message): string
    {
        return (string)((new DeterministicIntentParser())->parse($message, [])->toArray()['resolved_fields']['intent']['value'] ?? '');
    }

    public function testGapQuestionsAreFreeshipGap(): void
    {
        foreach ([
            'mua thêm gì để được free ship',
            'còn thiếu bao nhiêu để freeship',
            'mua thêm bao nhiêu để được miễn phí ship',
        ] as $message) {
            $this->assertSame('freeship_gap', $this->intentOf($message), $message);
        }
    }

    public function testPolicyQuestionAndCheckoutAreNotGapQuestions(): void
    {
        $this->assertSame('shipping', $this->intentOf('đơn bao nhiêu thì được freeship'));
        $this->assertSame('unsupported_checkout', $this->intentOf('thêm món rẻ nhất vào giỏ để được freeship'));
    }

    public function testPlannerAndAnswerForAGap(): void
    {
        $plan = (new ToolPlanner([]))->plan(['primary_intent' => 'freeship_gap', 'entities' => [], 'missing_slots' => []]);
        $this->assertSame(['tool' => 'suggest_freeship_items', 'args' => ['limit' => 3], 'id' => 'freeship'], $plan['batches'][0][0]);

        $intent = ['primary_intent' => 'freeship_gap', 'entities' => []];
        $normalized = (new EvidenceNormalizer())->normalize($intent, ['results' => ['freeship' => [
            'tool' => 'suggest_freeship_items', 'success' => true,
            'result' => ['status' => 'gap', 'threshold' => 500000, 'subtotal' => 415000, 'gap' => 85000,
                'products' => [['id' => 58, 'name' => 'Áo Thun Graphic Phối Màu', 'price' => 210000, 'stock' => 15]]],
        ]]]);
        $response = (new ResponseGenerator())->generate('mua thêm gì để free ship', $intent, $normalized, ['response_type' => 'final_answer']);

        $this->assertStringContainsString('còn thiếu 85.000đ', $response['message']);
        $this->assertStringContainsString('Áo Thun Graphic Phối Màu (mã 58)', $response['message']);
        $this->assertSame('gap', $response['freeship_status']);
        $this->assertSame([58], array_column($response['cards'], 'id'));
    }

    public function testAnswerForGuestsAndQualifiedCarts(): void
    {
        $intent = ['primary_intent' => 'freeship_gap', 'entities' => []];
        foreach ([
            'requires_login' => 'đăng nhập',
            'qualified' => 'đã đạt mức miễn phí ship',
            'empty_cart' => 'đang trống',
        ] as $status => $phrase) {
            $normalized = (new EvidenceNormalizer())->normalize($intent, ['results' => ['freeship' => [
                'tool' => 'suggest_freeship_items', 'success' => true,
                'result' => ['status' => $status, 'threshold' => 500000, 'subtotal' => 600000, 'gap' => 0, 'products' => []],
            ]]]);
            $message = (new ResponseGenerator())->generate('free ship', $intent, $normalized, ['response_type' => 'final_answer'])['message'];
            $this->assertStringContainsString($phrase, $message, $status);
        }
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: Parser.** `inferIntent()`: ngay sau dòng `isCheckout`, thêm `if ($this->isFreeShippingGap($lower)) return 'freeship_gap';`. Method mới:

```php
    private function isFreeShippingGap(string $text): bool {
        $freeShip = preg_match('/free\s*ship|freeship|miễn\s*phí\s*(?:ship|vận\s*chuyển|giao\s*hàng)|mien\s*phi\s*(?:ship|van\s*chuyen)/ui', $text);
        $gapLanguage = preg_match('/mua\s+thêm|mua\s+them|thêm\s+gì|them\s+gi|còn\s+thiếu|con\s+thieu|thiếu\s+bao\s+nhiêu|thieu\s+bao\s+nhieu|để\s+(?:được|đủ)|de\s+(?:duoc|du)/ui', $text);
        return $freeShip === 1 && $gapLanguage === 1;
    }
```

**Step 4: Manifest** thêm:

```php
        'freeship_gap' => [
            'tools' => ['suggest_freeship_items'],
            'required_slots' => [],
            'empty_response' => 'final_answer',
            'needs_auth' => false,
            'mutation' => false,
        ],
```

**Step 5: Pipeline**
- `ToolPlanner::buildCall()`:
  ```php
            case 'suggest_freeship_items':
                if ($primary !== 'freeship_gap') return null;
                return ['tool' => $tool, 'args' => ['limit' => 3], 'id' => 'freeship'];
  ```
- `PlanValidator::validateUseCase()`: `if ($primary === 'freeship_gap' && !$has('suggest_freeship_items')) $errors[] = 'freeship_gap_requires_suggest_freeship_items';`
- `CapabilityRegistry`: `relevantForPartial` thêm `'freeship_gap' => ['suggest_freeship_items'],`; `knownMetadata` thêm entry `use_when`/`do_not_use_when`/`output_schema` (các key `status`, `gap`, `threshold`, `products`).
- `LightweightEvidenceScorer`: `requiredFacts` thêm `'freeship_gap' => ['freeship_status'],`; đổi dòng return sớm thành `if (in_array($primary, ['build_outfit', 'freeship_gap'], true)) return $facts;`; `hasFact` thêm `'freeship_status' => $this->hasEvidenceType($evidence, 'freeship_status'),`.
- `EvidenceExecutionLoop`: `initialMissingEvidence` thêm `elseif ($primary === 'freeship_gap') { $missing[] = 'freeship_status'; }`; `goal` thêm `'freeship_gap' => 'close_free_shipping_gap',`.
- `LlmInputParser::INTENTS`: thêm `'freeship_gap',`.

**Step 6: `EvidenceNormalizer`**: nhánh `elseif ($tool === 'suggest_freeship_items') { $this->normalizeFreeShipping($result, $cards, $evidence); }` và:

```php
    private function normalizeFreeShipping(array $result, array &$cards, array &$evidence): void {
        foreach ($this->productCards(is_array($result['products'] ?? null) ? $result['products'] : []) as $card) {
            $cards[] = $card;
            $this->addProductFacts($card, $evidence, 'free_shipping');
        }
        $evidence[] = [
            'source' => 'free_shipping',
            'fact_type' => 'freeship_status',
            'value' => (string)($result['status'] ?? ''),
            'threshold' => (int)($result['threshold'] ?? 0),
            'subtotal' => (int)($result['subtotal'] ?? 0),
            'gap' => (int)($result['gap'] ?? 0),
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
    }
```

**Step 7: `ResponseGenerator`**: arm `'freeship_gap' => $this->freeShippingAnswer($evidence, $cards),`; khi `$primary === 'freeship_gap'` gán `$response['freeship_status']` bằng `value` của fact `freeship_status` (hoặc `null`). Method:

```php
    private function freeShippingAnswer(array $evidence, array $cards): string {
        $fact = [];
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'freeship_status') $fact = $item;
        }
        $threshold = $this->money((float)($fact['threshold'] ?? 0));
        return match ((string)($fact['value'] ?? '')) {
            'requires_login' => "Shop miễn phí ship cho đơn từ $threshold. Bạn đăng nhập để mình kiểm tra giỏ hàng và gợi ý món phù hợp nhé.",
            'empty_cart' => "Giỏ hàng của bạn đang trống. Shop miễn phí ship cho đơn từ $threshold.",
            'qualified' => 'Giỏ hàng của bạn (' . $this->money((float)$fact['subtotal']) . ") đã đạt mức miễn phí ship (đơn từ $threshold).",
            'gap' => $this->freeShippingGapAnswer($fact, $cards, $threshold),
            default => 'Mình chưa kiểm tra được giỏ hàng lúc này. Shop miễn phí ship cho đơn từ ' . $threshold . '.',
        };
    }

    private function freeShippingGapAnswer(array $fact, array $cards, string $threshold): string {
        $answer = sprintf(
            'Giỏ hàng của bạn đang là %s, còn thiếu %s để được miễn phí ship (đơn từ %s).',
            $this->money((float)$fact['subtotal']), $this->money((float)$fact['gap']), $threshold
        );
        $items = [];
        foreach (array_slice($cards, 0, 3) as $card) {
            $items[] = sprintf('%s (mã %d) %s', (string)$card['name'], (int)$card['id'], $this->money((float)$card['price']));
        }
        if ($items === []) {
            return $answer . ' Hiện mình chưa tìm được món còn hàng phù hợp để bù.';
        }
        return $answer . ' Bạn có thể thêm: ' . implode('; ', $items) . '. Bạn tự thêm vào giỏ từ thẻ sản phẩm nhé.';
    }
```

**Step 8: Response API**: thêm `'freeship_status'` vào key trả về ở `src/Http/Controllers/Chatbot/index.php` và `stream.php`.

**Step 9:** chạy Unit → `OK`. Commit:

```bash
git add -A && git commit -m "feat(chatbot): freeship_gap intent answers the free-shipping gap"
```

### Task 35: Gợi ý chủ động `FreeShippingNudge`

**Files:**
- Create: `src/Chatbot/FreeShipping/FreeShippingNudge.php`
- Modify: `src/Chatbot/ChatbotService.php`, `src/Http/Controllers/Chatbot/{index,stream}.php`, `mcp-server/src/chat-stream.ts`
- Test: `tests/Unit/FreeShippingNudgeTest.php`

**Step 1: Test (đỏ)**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingNudgeTest extends TestCase
{
    private FreeShippingPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new FreeShippingPolicy(500000, 200000);
    }

    private function advice(int $gap = 85000, string $fingerprint = 'abc'): array
    {
        return ['status' => 'gap', 'gap' => $gap, 'threshold' => 500000, 'cart_fingerprint' => $fingerprint,
            'products' => [['id' => 58, 'name' => 'Áo Thun Graphic Phối Màu', 'price' => 210000, 'stock' => 15]]];
    }

    private function turn(string $intent = 'product_search', string $type = 'final_answer'): array
    {
        return ['primary_intent' => $intent, 'response_type' => $type];
    }

    public function testEligibleOnlyForShoppingTurnsWithASmallGapAndANewCart(): void
    {
        $this->assertTrue(FreeShippingNudge::eligible($this->turn(), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('return_exchange'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('freeship_gap'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('product_search', 'fallback'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), $this->advice(250000), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), $this->advice(), 'abc', $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), ['status' => 'qualified'] + $this->advice(), null, $this->policy));
    }

    public function testLedgerRemembersTheLastNudgedCartInSessionSlots(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (9, '', '{\"size\":\"M\"}')");
        $ledger = new FreeShippingNudge($pdo, 9);

        $this->assertNull($ledger->lastFingerprint());
        $ledger->remember('abc');

        $this->assertSame('abc', $ledger->lastFingerprint());
        $slots = json_decode((string)$pdo->query('SELECT slots FROM chat_session_memory WHERE session_id = 9')->fetchColumn(), true);
        $this->assertSame('M', $slots['size'], 'existing slots are preserved');
    }

    public function testLedgerCreatesTheMemoryRowWhenMissing(): void
    {
        $pdo = SqliteShop::pdo();
        (new FreeShippingNudge($pdo, 4))->remember('xyz');

        $this->assertSame('xyz', (new FreeShippingNudge($pdo, 4))->lastFingerprint());
    }

    public function testMessageNamesGapThresholdAndProduct(): void
    {
        $text = FreeShippingNudge::message($this->advice());

        $this->assertStringContainsString('còn thiếu 85.000đ', $text);
        $this->assertStringContainsString('500.000đ', $text);
        $this->assertStringContainsString('Áo Thun Graphic Phối Màu', $text);
    }
}
```

**Step 2:** chạy → FAIL.

**Step 3: `src/Chatbot/FreeShipping/FreeShippingNudge.php`**

```php
<?php

/**
 * Proactive UC-C line appended to a shopping turn (spec §6.3). The last
 * nudged cart fingerprint lives in chat_session_memory.slots so the same
 * cart is never nudged twice in a session.
 */
final class FreeShippingNudge
{
    public const SHOPPING_INTENTS = [
        'product_search', 'product_detail', 'mixed_product_policy', 'build_outfit', 'shipping', 'order_status',
    ];
    private const SLOT = 'freeship_nudged_fingerprint';

    public function __construct(private PDO $pdo, private int $sessionId)
    {
    }

    public static function eligible(array $result, array $advice, ?string $lastFingerprint, FreeShippingPolicy $policy): bool
    {
        if (!in_array((string)($result['primary_intent'] ?? ''), self::SHOPPING_INTENTS, true)) return false;
        if (in_array((string)($result['response_type'] ?? ''), ['fallback', 'clarification'], true)) return false;
        if (($advice['status'] ?? '') !== FreeShippingGapAdvisor::GAP || empty($advice['products'])) return false;
        $gap = (int)($advice['gap'] ?? 0);
        if ($gap <= 0 || $gap > $policy->nudgeMaxGap) return false;
        return (string)($advice['cart_fingerprint'] ?? '') !== (string)$lastFingerprint;
    }

    public static function message(array $advice): string
    {
        $product = $advice['products'][0];
        return sprintf(
            'Giỏ hàng của bạn còn thiếu %s để được miễn phí ship (đơn từ %s); bạn có thể tham khảo thêm %s (mã %d) giá %s.',
            self::money((int)$advice['gap']),
            self::money((int)$advice['threshold']),
            (string)$product['name'],
            (int)$product['id'],
            self::money((int)round((float)$product['price']))
        );
    }

    public function lastFingerprint(): ?string
    {
        $value = $this->slots()[self::SLOT] ?? null;
        return is_string($value) ? $value : null;
    }

    public function remember(string $fingerprint): void
    {
        $stmt = $this->pdo->prepare('SELECT slots FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$this->sessionId]);
        $exists = $stmt->fetchColumn() !== false;
        $slots = $this->slots();
        $slots[self::SLOT] = $fingerprint;
        $json = json_encode($slots, JSON_UNESCAPED_UNICODE);
        if ($exists) {
            $this->pdo->prepare('UPDATE chat_session_memory SET slots = ? WHERE session_id = ?')->execute([$json, $this->sessionId]);
        } else {
            $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (?, '', ?)")->execute([$this->sessionId, $json]);
        }
    }

    /** @return array<string,mixed> */
    private function slots(): array
    {
        $stmt = $this->pdo->prepare('SELECT slots FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$this->sessionId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function money(int $value): string
    {
        return number_format($value, 0, ',', '.') . 'đ';
    }
}
```

**Step 4: Gắn vào `ChatbotService`**

Thêm hai method private:

```php
    /**
     * UC-C proactive nudge: computed after the grounded answer, at most once
     * per cart state. Failures never break the chat turn.
     *
     * @return array{text:string,card:array}|null
     */
    private function freeShippingNudge(array $result): ?array
    {
        if ($this->userId === null) return null;
        try {
            $policy = FreeShippingPolicy::fromEnvironment();
            $ledger = new FreeShippingNudge($this->pdo, $this->sessionId);
            $advice = (new FreeShippingService($this->pdo, $policy))->suggest($this->userId, ['limit' => 1]);
            if (!FreeShippingNudge::eligible($result, $advice, $ledger->lastFingerprint(), $policy)) return null;
            $ledger->remember((string)$advice['cart_fingerprint']);
            $cards = (new EvidenceNormalizer())->productCards($advice['products']);
            return $cards === [] ? null : ['text' => FreeShippingNudge::message($advice), 'card' => $cards[0]];
        } catch (Throwable) {
            error_log(json_encode(['operation' => 'freeship_nudge', 'success' => false, 'error_category' => 'suppressed_runtime_failure']));
            return null;
        }
    }

    private function withNudgeCard(array $result, ?array $nudge): array
    {
        $result['freeship_nudge'] = $nudge !== null;
        if ($nudge === null) return $result;
        $byId = [];
        foreach (($result['products'] ?? []) as $card) {
            if (is_array($card)) $byId[(int)($card['id'] ?? 0)] = $card;
        }
        $byId[(int)$nudge['card']['id']] ??= $nudge['card'];
        $result['products'] = array_values($byId);
        $result['cards'] = $result['products'];
        return $result;
    }
```

Trong `respond()`, ngay sau `$result = $this->runPipeline(...)`:

```php
        $nudge = $this->freeShippingNudge($result);
        $result = $this->withNudgeCard($result, $nudge);
        if ($nudge !== null) {
            $result['message'] = rtrim((string)$result['message']) . ' ' . $nudge['text'];
            $result['answer'] = $result['message'];
        }
```

Trong `respondStreaming()`, ngay sau `$result = $this->runPipeline(...)`:

```php
        $nudge = $this->freeShippingNudge($result);
        $result = $this->withNudgeCard($result, $nudge);
```

và ngay **sau** khối `if/elseif/else` stream (trước `saveMessages`). Nudge được phát như một delta riêng sau câu trả lời, để LLM viết lại câu trả lời không làm mất nó:

```php
        if ($nudge !== null) {
            $onDelta(' ' . $nudge['text']);
            $streamed .= ' ' . $nudge['text'];
            $result['message'] = $streamed;
            $result['answer'] = $streamed;
        }
```

**Step 5: Bề mặt response**
- `src/Http/Controllers/Chatbot/index.php`: thêm `'freeship_nudge'` vào danh sách key.
- `stream.php`: thêm `'freeship_nudge' => (bool)($result['freeship_nudge'] ?? false),`.
- `mcp-server/src/chat-stream.ts` (`safeCompletion`): thêm `if (typeof upstream.freeship_nudge === 'boolean') completion.freeship_nudge = upstream.freeship_nudge;`.

**Step 6: Kiểm tra**

```bash
scripts/dev/composer dump-autoload
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
npm --prefix mcp-server test && npm --prefix mcp-server run build
```
Expected: `OK`; `fail 0`.

**Step 7:** `git add -A && git commit -m "feat(chatbot): proactive free-shipping nudge once per cart state"`

---

# GIAI ĐOẠN 5: Eval mới, CI, tài liệu (spec §7, §9)

### Task 36: Bộ eval 40 câu + runner

**Files:**
- Create: `eval/cases.jsonl`, `eval/AgentEvalCaseRunner.php`, `scripts/run_agent_eval.php`

**Step 1: `eval/cases.jsonl`** (mỗi dòng một case; `turns` chạy tuần tự trong cùng một phiên chat; `cart` được tạo trước turn đầu):

```jsonl
{"id":"A01","group":"outfit","user":false,"turns":[{"message":"Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg","expect":{"intent":"build_outfit","tool":"build_outfit","max_total":800000,"first_set":[61,66]}}]}
{"id":"A02","group":"outfit","user":false,"turns":[{"message":"phối giúp tôi một set đồ đi chơi gồm áo và quần","expect":{"intent":"build_outfit","tool":"build_outfit","min_products":2}}]}
{"id":"A03","group":"outfit","user":false,"turns":[{"message":"Sản phẩm mã 50 phối với gì?","expect":{"intent":"build_outfit","products":0,"message_contains":"không còn hàng"}}]}
{"id":"A04","group":"outfit","user":false,"turns":[{"message":"Áo mã 61 phối với gì","expect":{"intent":"build_outfit","min_products":2,"all_sets_contain":61}}]}
{"id":"A05","group":"outfit","user":false,"turns":[{"message":"đi biển nên mặc gì, ngân sách 1 triệu","expect":{"intent":"build_outfit","max_total":1000000,"min_products":2}}]}
{"id":"A06","group":"outfit","user":false,"turns":[{"message":"set đồ dự tiệc dưới 2 triệu","expect":{"intent":"build_outfit","max_total":2000000,"min_products":1}}]}
{"id":"A07","group":"outfit","user":false,"turns":[{"message":"đi hẹn hò mặc gì size M","expect":{"intent":"build_outfit","min_products":1,"size":"M"}}]}
{"id":"A08","group":"outfit","user":false,"turns":[{"message":"outfit streetstyle tầm 700k","expect":{"intent":"build_outfit","max_total":700000,"first_set":[58,68]}}]}
{"id":"A09","group":"outfit","user":false,"turns":[{"message":"gợi ý set đồ tập gym","expect":{"intent":"build_outfit","min_products":2}}]}
{"id":"A10","group":"outfit","user":false,"turns":[{"message":"trời lạnh mặc gì đi làm, có 1.5 triệu","expect":{"intent":"build_outfit","max_total":1500000,"min_products":2}}]}
{"id":"A11","group":"outfit","user":false,"turns":[{"message":"mặc gì đi phỏng vấn có 300k","expect":{"intent":"build_outfit","products":0,"message_contains":"680.000đ"}}]}
{"id":"A12","group":"outfit","user":false,"turns":[{"message":"phối đồ giúp mình","expect":{"intent":"build_outfit","response_type":"clarification","products":0,"no_tools":true}}]}
{"id":"A13","group":"outfit","user":false,"turns":[{"message":"set đồ basic dưới 500k size L","expect":{"intent":"build_outfit","max_total":500000,"first_set":[77,99],"size":"L"}}]}
{"id":"A14","group":"outfit","user":false,"turns":[{"message":"đi cà phê cuối tuần nên mặc gì","expect":{"intent":"build_outfit","min_products":2}}]}
{"id":"A15","group":"outfit","user":false,"turns":[{"message":"set đồ dự tiệc dưới 500k","expect":{"intent":"build_outfit","products":0,"message_contains":"710.000đ"}}]}
{"id":"C01","group":"freeship","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"mua thêm gì để được free ship","expect":{"intent":"freeship_gap","tool":"suggest_freeship_items","freeship":"gap","first_product":58,"reaches_threshold":true,"message_contains":"85.000đ"}}]}
{"id":"C02","group":"freeship","user":true,"cart":[{"product_id":61,"quantity":1,"size":"M"},{"product_id":99,"quantity":2,"size":"S"}],"turns":[{"message":"còn thiếu bao nhiêu để freeship","expect":{"intent":"freeship_gap","freeship":"gap","first_product":58,"reaches_threshold":true}}]}
{"id":"C03","group":"freeship","user":true,"cart":[{"product_id":57,"quantity":1,"size":"M"}],"turns":[{"message":"mua thêm gì để được free ship","expect":{"intent":"freeship_gap","freeship":"qualified","products":0}}]}
{"id":"C04","group":"freeship","user":true,"cart":[],"turns":[{"message":"mua thêm gì để được free ship","expect":{"intent":"freeship_gap","freeship":"empty_cart","products":0}}]}
{"id":"C05","group":"freeship","user":false,"turns":[{"message":"mua thêm bao nhiêu để được miễn phí ship","expect":{"intent":"freeship_gap","freeship":"requires_login","products":0,"message_contains":"đăng nhập"}}]}
{"id":"C06","group":"freeship","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"tìm áo thun dưới 300k","expect":{"intent":"product_search","nudge":true,"message_contains":"còn thiếu 85.000đ"}},{"message":"có áo polo màu đỏ không","expect":{"intent":"product_search","nudge":false}}]}
{"id":"C07","group":"freeship","user":true,"cart":[{"product_id":61,"quantity":1,"size":"M"}],"turns":[{"message":"tìm quần jeans","expect":{"intent":"product_search","nudge":false}}]}
{"id":"C08","group":"freeship","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"chính sách đổi trả thế nào","expect":{"intent":"return_exchange","nudge":false}}]}
{"id":"C09","group":"freeship","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"còn thiếu bao nhiêu để freeship","expect":{"intent":"freeship_gap","freeship":"gap","first_product":58,"nudge":false,"message_contains":"85.000đ"}}]}
{"id":"C10","group":"freeship","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"Có 800k, cần đồ đi phỏng vấn","expect":{"intent":"build_outfit","max_total":800000,"nudge":true}}]}
{"id":"G01","group":"guardrail","user":false,"turns":[{"message":"thêm áo mã 52 size M vào giỏ giúp tôi","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0}}]}
{"id":"G02","group":"guardrail","user":false,"turns":[{"message":"checkout và thanh toán giúp tôi áo thun trắng size M","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0}}]}
{"id":"G03","group":"guardrail","user":false,"turns":[{"message":"chốt đơn áo mã 52 cho tôi","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0}}]}
{"id":"G04","group":"guardrail","user":false,"turns":[{"message":"đặt hàng giúp mình set đồ đi chơi","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0}}]}
{"id":"G05","group":"guardrail","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"thanh toán giúp tôi giỏ hàng","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0,"nudge":false}}]}
{"id":"G06","group":"guardrail","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"mua giúp tôi áo mã 61 để đủ free ship","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0,"nudge":false}}]}
{"id":"G07","group":"guardrail","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"thêm món rẻ nhất vào giỏ để được freeship","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0,"nudge":false}}]}
{"id":"G08","group":"guardrail","user":false,"turns":[{"message":"chốt đơn set đồ phỏng vấn cho mình","expect":{"intent":"unsupported_checkout","no_tools":true,"products":0}}]}
{"id":"U01","group":"unrelated","user":false,"turns":[{"message":"thời tiết hôm nay thế nào","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U02","group":"unrelated","user":false,"turns":[{"message":"1 + 1 bằng mấy","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U03","group":"unrelated","user":false,"turns":[{"message":"kể cho tôi một câu chuyện cười","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U04","group":"unrelated","user":false,"turns":[{"message":"ai là tổng thống Mỹ","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U05","group":"unrelated","user":false,"turns":[{"message":"dịch giúp tôi câu hello sang tiếng Nhật","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U06","group":"unrelated","user":false,"turns":[{"message":"viết code python sắp xếp mảng","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0}}]}
{"id":"U07","group":"unrelated","user":true,"cart":[{"product_id":53,"quantity":1,"size":"M"}],"turns":[{"message":"bạn tên là gì","expect":{"intent":"unknown","response_type":["fallback","clarification"],"no_tools":true,"products":0,"nudge":false}}]}
```

Các giá trị mong đợi được tính tay từ dữ liệu seed (giá, tồn kho, tag ở Task 22):
- A01 = 61 + 66 (790k); A08 = 58 + 68 (660k, set hợp lệ duy nhất); A11: set phỏng vấn rẻ nhất 61 + 88 = 680k; A13 = 77 + 99 (495k); A15: set dự tiệc rẻ nhất là váy 86 giá 710k.
- C01/C02/C09: khoảng thiếu 85k/160k, món sát nhất còn hàng là 58 (210k).

**Step 2: `eval/AgentEvalCaseRunner.php`**

```php
<?php

declare(strict_types=1);

/**
 * Runs one eval case in-process: creates a throwaway user/cart/session,
 * sends each turn through ChatbotService (internal tools, no LLM) and checks
 * the expectations. All rows it creates are deleted afterwards.
 */
final class AgentEvalCaseRunner
{
    public function __construct(private PDO $pdo, private FreeShippingPolicy $policy)
    {
    }

    /** @return array{id:string,group:string,failures:list<string>,turns:list<array<string,mixed>>} */
    public function run(array $case): array
    {
        $userId = !empty($case['user']) ? $this->createUser() : null;
        $sessionId = $this->createSession($userId);
        $failures = [];
        $turns = [];
        try {
            $subtotal = 0;
            foreach (($case['cart'] ?? []) as $row) {
                $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, (int)$row['product_id'], (int)$row['quantity'], (string)($row['size'] ?? 'S')]);
                $subtotal += $this->price((int)$row['product_id']) * (int)$row['quantity'];
            }
            foreach ($case['turns'] as $i => $turn) {
                $result = (new ChatbotService($this->pdo, $sessionId, $userId, null))->respond((string)$turn['message']);
                foreach ($this->check($result, (array)($turn['expect'] ?? []), $subtotal) as $failure) {
                    $failures[] = 'turn ' . ($i + 1) . ': ' . $failure;
                }
                $turns[] = [
                    'message' => $turn['message'],
                    'intent' => $result['primary_intent'] ?? null,
                    'response_type' => $result['response_type'] ?? null,
                    'tools' => $result['latency']['routing']['selected_tools'] ?? [],
                    'product_ids' => array_map(fn($card) => (int)$card['id'], $result['products'] ?? []),
                    'outfits' => $result['outfits'] ?? null,
                    'freeship_status' => $result['freeship_status'] ?? null,
                    'freeship_nudge' => $result['freeship_nudge'] ?? null,
                    'answer' => $result['message'] ?? '',
                ];
            }
        } catch (Throwable $error) {
            $failures[] = 'exception: ' . $error->getMessage();
        } finally {
            $this->cleanup($userId, $sessionId);
        }
        return ['id' => (string)$case['id'], 'group' => (string)($case['group'] ?? ''), 'failures' => $failures, 'turns' => $turns];
    }

    /** @return list<string> */
    private function check(array $result, array $expect, int $subtotal): array
    {
        $failures = [];
        $intent = (string)($result['primary_intent'] ?? '');
        $cards = array_values(array_filter($result['products'] ?? [], 'is_array'));
        $ids = array_map(fn(array $card) => (int)$card['id'], $cards);
        $tools = (array)($result['latency']['routing']['selected_tools'] ?? []);
        $outfits = is_array($result['outfits'] ?? null) ? $result['outfits'] : [];

        if (isset($expect['intent']) && $intent !== $expect['intent']) $failures[] = "intent $intent, expected {$expect['intent']}";
        if (isset($expect['response_type']) && !in_array($result['response_type'] ?? '', (array)$expect['response_type'], true)) {
            $failures[] = 'response_type ' . ($result['response_type'] ?? 'null');
        }
        if (isset($expect['tool']) && !in_array($expect['tool'], $tools, true)) $failures[] = "tool {$expect['tool']} not used";
        if (!empty($expect['no_tools']) && $tools !== []) $failures[] = 'tools used: ' . implode(',', $tools);
        if (isset($expect['products']) && count($ids) !== (int)$expect['products']) $failures[] = 'product count ' . count($ids);
        if (isset($expect['min_products']) && count($ids) < (int)$expect['min_products']) $failures[] = 'product count ' . count($ids);

        // Grounding: every card is a real catalog product; recommendations are in stock.
        foreach ($ids as $id) {
            $stock = $this->stock($id);
            if ($stock === null) $failures[] = "hallucinated product $id";
            elseif ($stock <= 0 && in_array($intent, ['build_outfit', 'freeship_gap'], true)) $failures[] = "out-of-stock product $id";
        }

        if (isset($expect['max_total'])) {
            foreach ($outfits as $outfit) {
                if ((int)$outfit['total_price'] > (int)$expect['max_total']) {
                    $failures[] = "outfit {$outfit['outfit_index']} total {$outfit['total_price']} over budget";
                }
            }
        }
        if (isset($expect['first_set'])) {
            $first = array_map('intval', (array)($outfits[0]['product_ids'] ?? []));
            if ($first !== array_map('intval', $expect['first_set'])) $failures[] = 'first set ' . json_encode($first);
        }
        if (isset($expect['all_sets_contain'])) {
            foreach ($outfits as $outfit) {
                if (!in_array((int)$expect['all_sets_contain'], array_map('intval', $outfit['product_ids']), true)) {
                    $failures[] = "outfit {$outfit['outfit_index']} lacks anchor {$expect['all_sets_contain']}";
                }
            }
        }
        if (isset($expect['size'])) {
            foreach ($cards as $card) {
                if (in_array((int)($card['category_id'] ?? 0), [1, 2, 3], true)
                    && !in_array($expect['size'], (array)($card['available_sizes'] ?? []), true)) {
                    $failures[] = "product {$card['id']} lacks size {$expect['size']}";
                }
            }
        }
        if (isset($expect['freeship']) && ($result['freeship_status'] ?? null) !== $expect['freeship']) {
            $failures[] = 'freeship_status ' . json_encode($result['freeship_status'] ?? null);
        }
        if (isset($expect['first_product']) && ($ids[0] ?? null) !== (int)$expect['first_product']) {
            $failures[] = 'first product ' . json_encode($ids[0] ?? null);
        }
        if (!empty($expect['reaches_threshold'])) {
            foreach ($cards as $card) {
                if ($subtotal + (int)round((float)$card['price']) < $this->policy->threshold) {
                    $failures[] = "product {$card['id']} does not reach the free-shipping threshold";
                }
            }
        }
        if (isset($expect['nudge']) && (bool)($result['freeship_nudge'] ?? false) !== (bool)$expect['nudge']) {
            $failures[] = 'freeship_nudge ' . json_encode($result['freeship_nudge'] ?? null);
        }
        if (isset($expect['message_contains']) && mb_stripos((string)($result['message'] ?? ''), (string)$expect['message_contains']) === false) {
            $failures[] = 'answer lacks "' . $expect['message_contains'] . '"';
        }
        return $failures;
    }

    private function stock(int $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    private function price(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT price FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int)round((float)$stmt->fetchColumn());
    }

    private function createUser(): int
    {
        $tag = bin2hex(random_bytes(6));
        $this->pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')")
            ->execute(["eval_$tag", "eval_$tag@example.test", password_hash($tag, PASSWORD_DEFAULT)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createSession(?int $userId): int
    {
        $this->pdo->prepare("INSERT INTO chat_sessions (user_id, session_token, status) VALUES (?, ?, 'active')")
            ->execute([$userId, bin2hex(random_bytes(16))]);
        return (int)$this->pdo->lastInsertId();
    }

    private function cleanup(?int $userId, int $sessionId): void
    {
        $statements = [
            ['DELETE FROM chat_messages WHERE session_id = ?', $sessionId],
            ['DELETE FROM tool_executions WHERE session_id = ?', $sessionId],
            ['DELETE FROM chat_session_memory WHERE session_id = ?', $sessionId],
            ['DELETE FROM chat_sessions WHERE id = ?', $sessionId],
        ];
        if ($userId !== null) {
            $statements[] = ['DELETE FROM cart WHERE user_id = ?', $userId];
            $statements[] = ['DELETE FROM user_long_term_memory WHERE user_id = ?', $userId];
            $statements[] = ['DELETE FROM users WHERE id = ?', $userId];
        }
        foreach ($statements as [$sql, $id]) {
            try {
                $this->pdo->prepare($sql)->execute([$id]);
            } catch (PDOException) {
                // Optional tables may be absent on minimal schemas.
            }
        }
    }
}
```

**Step 3: `scripts/run_agent_eval.php`**

```php
<?php

declare(strict_types=1);

/**
 * Deterministic agent eval: UC-A outfits, UC-C free-shipping, guardrails and
 * unrelated suppression. In-process ChatbotService, internal tools, no LLM.
 * Usage: php scripts/run_agent_eval.php [--cases=eval/cases.jsonl] [--output=reports/eval/agent_eval.json]
 */
putenv('CHATBOT_TOOL_TRANSPORT=internal');
putenv('APP_ENV=test');
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/eval/AgentEvalCaseRunner.php';

/** @var PDO $pdo */
$options = getopt('', ['cases::', 'output::']);
$casesFile = (string)($options['cases'] ?? dirname(__DIR__) . '/eval/cases.jsonl');
$output = (string)($options['output'] ?? dirname(__DIR__) . '/reports/eval/agent_eval.json');

$runner = new AgentEvalCaseRunner($pdo, FreeShippingPolicy::fromEnvironment());
$report = ['generated_at' => date('c'), 'passed' => 0, 'failed' => 0, 'by_group' => [], 'cases' => []];
foreach (file($casesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $outcome = $runner->run($case);
    $passed = $outcome['failures'] === [];
    $report[$passed ? 'passed' : 'failed']++;
    $group = $outcome['group'];
    $report['by_group'][$group] = ($report['by_group'][$group] ?? 0) + ($passed ? 1 : 0);
    $report['cases'][] = $outcome;
    fwrite(STDOUT, sprintf("%s %s %s\n", $passed ? 'PASS' : 'FAIL', $outcome['id'], implode('; ', $outcome['failures'])));
}
if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$total = $report['passed'] + $report['failed'];
fwrite(STDOUT, sprintf("%d/%d passed -> %s\n", $report['passed'], $total, $output));
exit($report['failed'] === 0 && $total > 0 ? 0 : 1);
```

**Step 4: Chạy trên `shop_test`**

```bash
scripts/dev/test-db.sh
set -a; . ./.env; set +a
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$MARIADB_ROOT_PASSWORD" \
  scripts/dev/php scripts/run_agent_eval.php
```
Expected: `40/40 passed`.

Nếu có case FAIL:
1. Đọc `reports/eval/agent_eval.json` (chứa intent, tool, product id, câu trả lời của từng turn).
2. Xác định lỗi nằm ở code hay ở kỳ vọng. Các con số trong case đã tính từ dữ liệu seed, nên **mặc định nghi code trước**. Áp dụng skill `superpowers:systematic-debugging`.
3. Chỉ sửa case khi chứng minh được kỳ vọng sai (ghi lý do vào commit message). Không nới kỳ vọng để cho qua.

**Step 5: Commit**

```bash
git add eval/cases.jsonl eval/AgentEvalCaseRunner.php scripts/run_agent_eval.php
git commit -m "test(eval): 40-case deterministic agent eval for outfits and free shipping"
```

### Task 37: CI chạy eval mới

**Files:** `.github/workflows/ci.yml`, `Dockerfile` (không đổi: eval không vào image)

- Job `integration-tests`, thêm sau bước "Run Integration Tests":

```yaml
      - name: Agent eval (UC-A outfits, UC-C free shipping, guardrails)
        env:
          APP_ENV: test
          CHATBOT_TOOL_TRANSPORT: internal
          DB_HOST: 127.0.0.1
          DB_NAME: shop_test
          DB_USER: root
          DB_PASS: root_pass
        run: php scripts/run_agent_eval.php
```

- Trong job `security` (hoặc job có bước `Check for hardcoded secrets`), nếu có danh sách thư mục quét thì đổi `api` thành `src public`.

```bash
grep -n "run_agent_eval\|import schema\|database/schema.sql" .github/workflows/ci.yml
```
Expected: thấy bước mới và bước import schema mới.

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run the agent eval after integration tests"
```

### Task 38: Tài liệu (`README.md`, `CLAUDE.md`, `.claude/rules/`, `docs/`)

**Step 1: Xoá tài liệu mô tả hệ thống đã gỡ**

```bash
git rm -q .claude/rules/styling-providers.md .claude/rules/events-proactive.md
grep -rliE "stylitics|findmine|proactive|UC1|UC2|fashion-outbox|fashion-event|run_findmine|ragas" \
  README.md CLAUDE.md .claude docs DOCKER.md | grep -v "docs/superpowers\|docs/plans"
```

**Step 2: Viết lại `CLAUDE.md`** đúng nội dung sau:

````markdown
# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Fashion shop (PHP 8.2 + MariaDB + Docker Compose) with a deterministic RAG chatbot. The LLM never writes SQL and never picks tools; PHP resolves intent, plans tools from a manifest and validates every product card against the private catalog. Spec: `docs/superpowers/specs/2026-10-15-outfit-tags-freeship-design.md`.

## Commands

The host has no PHP; `scripts/dev/php` and `scripts/dev/composer` run them in Docker.

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit              # hermetic (SQLite fixtures)
scripts/dev/php vendor/bin/phpunit tests/Unit/OutfitBuilderTest.php
scripts/dev/test-db.sh && scripts/dev/test-integration.sh         # shop_test in Compose MariaDB
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/composer dump-autoload                                # after adding a class under src/
npm --prefix mcp-server test && npm --prefix mcp-server run build
docker compose config --quiet
scripts/dev/smoke.sh                                              # HTTP smoke incl. 404 on source paths
```

Agent eval (40 cases, needs shop_test): `DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS=... scripts/dev/php scripts/run_agent_eval.php` — report in `reports/eval/` (gitignored).

Local bring-up: `cp .env.example .env`, fill `LLM_*` + `MCP_SERVICE_TOKEN`, `docker compose up -d --build`, then the migration gate `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`. Migrations never run on web requests.

## Layout

- `public/` is the only web root: storefront pages, `admin/`, `assets/`, and the REST front controller `public/api/index.php`.
- `src/` holds all PHP code (Composer **classmap**, no namespaces): `Http/` (routes table `routes.php`, procedural controllers), `Shop/` (catalog, cart, order, size services), `Chatbot/` (pipeline, tools, outfit, free shipping), `Infrastructure/` (cache, Langfuse, migrations). New REST endpoints are registered in `src/Http/routes.php`.
- `templates/partials/` page header/footer/chat widget; `database/schema.sql` + `database/migrations/*.sql` (dated, never edit applied ones).
- Nginx edge-caches public catalog GETs for 60s (`docker/nginx/default.conf`); stale reads after DB edits are expected.

## Chatbot architecture

```
ChatbotService
  -> IntentResolver (DeterministicIntentParser first; LLM only enriches)
  -> EvidenceExecutionLoop: ToolPlanner + PlanValidator from config/chatbot_tools.php
  -> tool gateway: McpToolGateway (mcp-server stdio child -> /api/internal/mcp) or ToolRegistry (in-process)
       both execute through ToolApplicationService
  -> EvidenceNormalizer + ProductConstraintVerifier
  -> ResponseGenerator (template) / StreamingResponseGenerator (LLM rewrite of the grounded draft)
  -> FreeShippingNudge (UC-C proactive line, once per cart state)
```

- Adding an intent/tool = one manifest entry + planner builder + validator rule + scorer facts + normalizer/answer; tool contracts live in `ToolDefinitionCatalog` and `mcp-server/src/tools.ts` (keep both in sync; `contract.test.ts` checks the list).
- **UC-A `build_outfit`**: `products.style_tags` (1-3 of the 10 `StyleTag::ALL` tags, curated in migration `2026_10_15_product_style_tags.sql`) → `OutfitBuilder` composes top+bottom or dress (+ accessory) within budget, size and the one-statement-piece palette rule.
- **UC-C `freeship_gap`** + proactive nudge: `FreeShippingGapAdvisor` picks in-stock items with the smallest overshoot of `FREE_SHIPPING_THRESHOLD`; the nudge's last cart fingerprint is stored in `chat_session_memory.slots`.
- MCP is a private stdio child; nginx 404s `/api/internal/mcp`; it is authed by `X-MCP-SERVICE-TOKEN` (`hash_equals`). `chat-stream` forwards only allow-listed card fields.

## Hard rules

- Never auto-checkout, add to cart, or take payment from the chatbot (guardrail intent `unsupported_checkout`).
- Product cards come only from the private catalog and must pass `ProductConstraintVerifier`.
- `.env`, reports, model caches and local DB artifacts stay out of git.

## Rules index (`.claude/rules/`)

| File | Scope |
| --- | --- |
| `architecture.md` | Runtime services, pipeline internals, MCP contract |
| `database.md` | Schema, migrations, `MigrationRunner` |
| `testing.md` | Suites, fixtures, eval, CI gates |
| `eval.md` | Agent eval cases and how to read the report |
| `observability.md` | Langfuse profile, trace outbox, secrets |
| `deployment.md` | Compose, CI/CD deploy gate |
````

**Step 3: Cập nhật `.claude/rules/`**
- `architecture.md`: bảng service xoá `fashion-outbox-publisher`, `fashion-event-consumer`; dòng "App and both workers share..." → "App uses `${APP_IMAGE:-shop_quan_ao-app:latest}`"; mục pipeline đổi đường dẫn `api/controllers/chatbot/` → `src/Chatbot/`, thêm `build_outfit`/`freeship_gap`; mục MCP đổi `api/controllers/internal/mcp.php` → `src/Http/Controllers/Internal/mcp.php`; streaming boundary bỏ nhắc Stylitics.
- `database.md`: `sql/` → `database/`; runner là `MigrationRunner` (gọi từ `scripts/run_database_migrations.php`), không chạy khi xử lý request; thêm `scripts/dev/test-db.sh`.
- `testing.md`: thay toàn bộ lệnh bằng bộ lệnh `scripts/dev/*` như `CLAUDE.md`; danh sách job CI: bỏ "70-question corpus", thêm "Agent eval (40 cases) sau integration"; quy ước: fixture SQLite ở `tests/Support/SqliteShop.php`.
- `eval.md`: viết lại. Mô tả `eval/cases.jsonl` (15 outfit, 10 free ship, 8 guardrail, 7 unrelated), schema `expect`, lệnh chạy, cách đọc `reports/eval/agent_eval.json`, nguyên tắc "nghi code trước khi sửa kỳ vọng". Xoá mọi phần RAGAS/Stylitics/latency cũ.
- `deployment.md`: xoá dòng Stylitics; "App + both workers use the same..." → "App uses the `${APP_IMAGE...}` tag"; thêm smoke `scripts/dev/smoke.sh`.
- `observability.md`: xoá mục "Publish a live run report" (`eval/publish_stylitics_langfuse.py` đã bị xoá) và câu nhắc "Evaluator traces".

**Step 4: `README.md`**
- Tiêu đề và đoạn mở đầu: bỏ Stylitics; mô tả chatbot deterministic + 2 use case mới.
- Bảng "Chức năng": xoá hàng UC1/UC2 và câu "câu phối đồ chung... vẫn bị từ chối"; thêm:
  - `UC-A Phối set` — hỏi "Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg" → 1-2 set còn hàng, đúng size, tổng ≤ ngân sách, theo tag dịp/phong cách.
  - `UC-C Free ship` — hỏi "mua thêm gì để được free ship" hoặc nhận gợi ý chủ động khi giỏ còn thiếu ≤ 200k so với 500k.
- Sơ đồ kiến trúc: bỏ nhánh Stylitics và nhánh outbox → Redis → worker.
- Bảng service: xoá 2 worker (đã có `chat-stream` từ trước).
- Xoá mục "Chế độ Stylitics", "Kết quả cuối — 2026-08-26", "Agent Evaluation 50 câu", "Latency cuối", "RAGAS cuối".
- Mục "Kiểm thử": thay bằng lệnh `scripts/dev/*` + eval 40 câu.
- Thêm mục "Cấu trúc thư mục" ngắn (`public/`, `src/`, `templates/`, `database/`, `mcp-server/`, `eval/`).

**Step 5: `docs/`**: đọc lần lượt `docs/chatbot-spec.md`, `docs/technical-spec.md`, `docs/SRS_Chatbot_ISO_IEC_IEEE_29148_2018.md`, `docs/Manual_Test_Cases_Chatbot.md`, `docs/langfuse-observability.md`, `DOCKER.md`. Ở mỗi chỗ nhắc tới Stylitics/FindMine/UC1/UC2/worker/đường dẫn cũ (`api/`, `sql/`, `includes/`), sửa cho khớp hệ thống mới, hoặc thêm ghi chú đầu file "Tài liệu lịch sử, mô tả kiến trúc trước 2026-10-15" khi phải viết lại quá nhiều (SRS).

**Step 6: Kiểm tra**

```bash
grep -rniE "stylitics|findmine|proactive_styling|fashion-outbox|fashion-event|run_findmine|api/controllers|sql/migrations|includes/" \
  README.md CLAUDE.md .claude DOCKER.md docs | grep -v "docs/superpowers\|docs/plans\|Tài liệu lịch sử"
```
Expected: không còn dòng nào (trừ file được đánh dấu tài liệu lịch sử).

**Step 7:** `git add -A && git commit -m "docs: describe the new layout, outfit and free-shipping use cases"`

### Task 39: Kiểm tra cuối và bàn giao

**Step 1: Toàn bộ cổng**

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/php vendor/bin/phpcs --standard=PSR12 --extensions=php --ignore=vendor,reports,public/assets src || true
npm --prefix mcp-server ci && npm --prefix mcp-server test && npm --prefix mcp-server run build
docker compose config --quiet
scripts/dev/test-db.sh && scripts/dev/test-integration.sh
set -a; . ./.env; set +a
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$MARIADB_ROOT_PASSWORD" scripts/dev/php scripts/run_agent_eval.php
docker compose up -d --build && docker compose run --rm --no-deps app php scripts/run_database_migrations.php
sleep 5 && scripts/dev/smoke.sh
```
Expected: mọi bước xanh; eval `40/40 passed`; smoke toàn `ok`. PHPCS chỉ mang tính tham khảo (CI để non-blocking), nhưng không được có lỗi mới trong `src/Chatbot/Outfit` và `src/Chatbot/FreeShipping`.

**Step 2: Demo thủ công qua widget chat** (đăng nhập user thường):
1. "Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg" → 2 set, set 1 là áo 61 + quần tây 66 (790.000đ).
2. Thêm áo 53 (size M) vào giỏ từ trang sản phẩm, rồi hỏi "tìm áo thun dưới 300k" → cuối câu trả lời có dòng gợi ý free ship (còn thiếu 85.000đ) + thẻ áo 58. Hỏi tiếp một câu tìm sản phẩm khác → **không** lặp lại gợi ý.
3. "mua thêm gì để được free ship" → 3 gợi ý, món đầu là áo 58.
4. "thêm món rẻ nhất vào giỏ để được freeship" → bot từ chối tự thêm giỏ.

Ghi kết quả (ảnh chụp nếu được) vào mô tả PR.

**Step 3: Rà soát cuối**

```bash
git log --oneline develop..HEAD
git diff --stat develop..HEAD | tail -3
grep -rniE "stylitics|findmine" $(git ls-files | grep -v "docs/superpowers\|docs/plans") || echo "clean"
```

**Step 4: Bàn giao.** Dùng skill `superpowers:finishing-a-development-branch` để chọn merge hoặc tạo PR vào `develop`. Mô tả PR (kết thúc bằng dòng attribution) phải nêu:
- Các bảng bị `DROP` (migration `2026_10_15_drop_styling_providers.sql`).
- Migration đã chuyển ra khỏi request path: môi trường deploy **phải** chạy `scripts/run_database_migrations.php` (CI deploy đã có bước này).
- Webroot đổi sang `public/`, URL asset đổi `/css` → `/assets/css`, `/images` → `/assets/images`.
- Điều chỉnh tie-break khi có ngân sách (chọn tổng cao hơn) so với spec §5.4.
