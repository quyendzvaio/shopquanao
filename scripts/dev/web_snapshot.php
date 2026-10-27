<?php

/**
 * HTTP snapshot harness for the storefront pages, the admin pages and the REST
 * API. Runs inside the app image (see scripts/dev/web-snapshot.sh):
 *
 *   php scripts/dev/web_snapshot.php <outdir> [--output-buffering=4096]
 *
 * It seeds the database named by DB_* (a FRESH shop_test: the script refuses
 * to run twice), starts `php -S` with scripts/dev/web_router.php, replays a
 * fixed list of requests with per-actor cookie jars and writes one file per
 * scenario: `NN-<scenario>.txt` holding `status:`, `location:`, `content-type:`,
 * a blank line and the normalised body, plus one `zz-server-log-<server>.txt`
 * per server with its normalised PHP error log (empty when clean). Two runs against a fresh database must be
 * byte-identical; later refactor tasks diff against a stored baseline.
 *
 * State-changing requests are POSTs with a CSRF token: a scenario reads the token from the form the page renders
 * (`token()`, an unrecorded GET) and posts it. Every action also has a wrong-token scenario and, where it used to be
 * a GET link, an old-GET scenario; `noChange()` checks the database did not move for those.
 *
 * No image is ever uploaded, so nothing is written under public/assets/images/.
 */

const SNAP_HOST = '127.0.0.1';
const SNAP_PORT = 8099;

const SNAP_USER_EMAIL = 'snap-user@example.test';
const SNAP_USER_PASS = 'snap-pass-1';
const SNAP_OTHER_EMAIL = 'snap-other@example.test';
const SNAP_ADMIN_NAME = 'snap-admin';
const SNAP_ADMIN_PASS = 'snap-admin-pass';
const SNAP_ENV_ADMIN_EMAIL = 'snap-env-admin@example.test';
const SNAP_ENV_ADMIN_PASS = 'snap-env-pass';
const SNAP_ENV_ADMIN_USER = 'snap-env-admin-user';

/** Percent-encode everything outside printable ASCII (Vietnamese, spaces). */
function snapEncodePath(string $path): string
{
    return preg_replace_callback('/[^\x21-\x7e]/', static fn(array $m): string => rawurlencode($m[0]), $path) ?? $path;
}

/**
 * Flatten nested fields to `name[0]` keys: CURLOPT_POSTFIELDS multipart arrays
 * cannot hold nested arrays.
 *
 * @param array<string, mixed> $fields
 * @return array<string, string>
 */
function snapFlatten(array $fields, string $prefix = ''): array
{
    $flat = [];
    foreach ($fields as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            $flat += snapFlatten($value, $name);
        } else {
            $flat[$name] = (string) $value;
        }
    }
    return $flat;
}

/** Replace every random or time-based value so two runs are byte-identical. */
function snapNormalise(string $body): string
{
    $rules = [
        '/\b[0-9a-f]{64}\b/i' => '<hex64>',
        '/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/' => '<ts>',
        '/\b\d{1,2}\/\d{1,2}\/\d{4}\b(?: \d{2}:\d{2})?/' => '<date>',
        // chat widget session token (random, 32 hex)
        '/\b[0-9a-f]{32}\b/i' => '<hex32>',
        '/PHPSESSID=[A-Za-z0-9,-]+/' => 'PHPSESSID=<sid>',
    ];
    foreach ($rules as $pattern => $replacement) {
        $body = preg_replace($pattern, $replacement, $body) ?? $body;
    }
    return $body;
}

/** Normalise a PHP error log: timestamps, temp paths and random values. */
function snapNormaliseLog(string $log): string
{
    $rules = [
        '/\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [A-Za-z\/_]+\]/' => '[<ts>]',
        '#/[^\s:\'"]*web-snapshot-\d+(?:/phase\d+)?#' => '<tmp>',
    ];
    foreach ($rules as $pattern => $replacement) {
        $log = preg_replace($pattern, $replacement, $log) ?? $log;
    }
    return snapNormalise($log);
}

final class SnapRunner
{
    /** @var array<string, CurlHandle> one handle (cookie jar) per actor */
    private array $actors = [];
    private int $seq = 0;
    /** @var list<string> */
    public array $failures = [];
    /** @var array<string, array{status: int, location: string}> by scenario name */
    public array $results = [];

    public function __construct(private string $outDir, private string $baseUrl)
    {
    }

    public function seq(): int
    {
        return $this->seq;
    }

    /** Forget every cookie jar (used when the server is restarted). */
    public function resetActors(): void
    {
        foreach ($this->actors as $ch) {
            curl_close($ch);
        }
        $this->actors = [];
    }

    private function handle(string $actor): CurlHandle
    {
        if (!isset($this->actors[$actor])) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEFILE => '',
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING => '',
            ]);
            $this->actors[$actor] = $ch;
        }
        return $this->actors[$actor];
    }

    /**
     * @param array<string, mixed>|string|null $body array = form fields, string = raw body
     * @param list<string> $headers extra request headers
     * @return array{status: int, body: string, location: string}|null
     */
    public function request(
        string $scenario,
        string $actor,
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        bool $multipart = false,
    ): ?array {
        $this->seq++;
        $file = sprintf('%s/%02d-%s.txt', $this->outDir, $this->seq, $scenario);
        $ch = $this->handle($actor);
        $opts = [
            CURLOPT_URL => $this->baseUrl . snapEncodePath($path),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'GET' && $body === null) {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_POST] = true;
            if (is_array($body)) {
                $opts[CURLOPT_POSTFIELDS] = $multipart ? snapFlatten($body) : http_build_query($body);
            } else {
                $opts[CURLOPT_POSTFIELDS] = $body ?? '';
            }
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->failures[] = sprintf('%s (%s %s): %s', $scenario, $method, $path, curl_error($ch));
            return null;
        }
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);
        $location = '-';
        if (preg_match_all('/^Location:[ \t]*(.*?)\r?$/mi', $rawHeaders, $m) && $m[1] !== []) {
            $location = (string) end($m[1]);
        }

        $contentType = '-';
        if (preg_match_all('/^Content-Type:[ \t]*(.*?)\r?$/mi', $rawHeaders, $m) && $m[1] !== []) {
            $contentType = (string) end($m[1]);
        }
        $this->results[$scenario] = ['status' => $status, 'location' => $location];

        $content = 'status: ' . $status . "\nlocation: " . snapNormalise($location)
            . "\ncontent-type: " . snapNormalise($contentType) . "\n\n" . snapNormalise($responseBody);
        if (file_put_contents($file, $content) === false) {
            $this->failures[] = sprintf('%s: cannot write %s', $scenario, $file);
            return null;
        }
        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }

    /**
     * The CSRF token a page renders in its first `csrf_token` hidden input. An unrecorded GET: it neither numbers a
     * scenario nor writes a file, so the scenario list only shows the real actions.
     */
    public function token(string $actor, string $path): string
    {
        $ch = $this->handle($actor);
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . snapEncodePath($path),
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [],
        ]);
        $raw = curl_exec($ch);
        $body = $raw === false ? '' : substr((string) $raw, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $body, $m) !== 1) {
            $this->failures[] = sprintf('token (%s %s): no csrf_token field in the page', $actor, $path);
            return '';
        }
        return $m[1];
    }

    /** Runs $do and records a failure when `$probe()` (a database dump) differs before and after. */
    public function noChange(string $scenario, callable $probe, callable $do): void
    {
        $before = $probe();
        $do();
        if ($probe() !== $before) {
            $this->failures[] = sprintf('%s: the database changed, but this request must change nothing', $scenario);
        }
    }

    /** JSON request helper for the REST API; returns the decoded body. */
    public function api(
        string $scenario,
        string $actor,
        string $method,
        string $path,
        ?array $json = null,
        ?string $token = null,
    ): ?array {
        $headers = [];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $res = $this->request($scenario, $actor, $method, $path, $body, $headers);
        if ($res === null) {
            return null;
        }
        $decoded = json_decode($res['body'], true);
        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * Environment of the `php -S` child, passed explicitly to proc_open. It starts
 * from this process' environment on purpose: the image's ini files interpolate
 * PHP_* variables (max_input_vars, memory_limit, ...), and dropping them makes
 * PHP treat the limits as 0 (empty $_POST). ADMIN_* are removed first so only
 * the second server can see them.
 *
 * @param array<string, string> $extra
 * @return array<string, string>
 */
function snapServerEnv(array $extra): array
{
    $env = [];
    foreach (getenv() as $key => $value) {
        if (is_string($value) && !in_array($key, ['ADMIN_EMAIL', 'ADMIN_PASSWORD', 'ADMIN_USER'], true)) {
            $env[(string) $key] = $value;
        }
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        $env[$key] = (string) getenv($key);
    }
    $env['APP_ENV'] = 'test';
    // Never touch the Compose Redis: an unreachable port makes Cache fall back
    // to files, and TMPDIR gives each server its own empty cache directory.
    $env['REDIS_HOST'] = '127.0.0.1';
    $env['REDIS_PORT'] = '9';
    return $extra + $env;
}

/**
 * Start `php -S` with the web-SAPI ini of the production image (PHP-FPM:
 * output_buffering=4096, display_errors=Off, errors logged, UTC).
 *
 * @param array<string, string> $env
 * @return array{proc: resource, log: string}
 */
function snapStartServer(string $root, array $env, string $outputBuffering): array
{
    $log = $env['TMPDIR'] . '/web-snapshot-server-' . getmypid() . '-' . count(glob($env['TMPDIR'] . '/web-snapshot-server-*') ?: []) . '.log';
    $cmd = [
        PHP_BINARY,
        '-d', 'output_buffering=' . $outputBuffering,
        '-d', 'display_errors=Off',
        '-d', 'log_errors=1',
        '-d', 'error_log=' . $log,
        '-d', 'error_reporting=22527',
        '-d', 'date.timezone=UTC',
        '-d', 'default_charset=UTF-8',
        '-d', 'expose_php=0',
        '-S', SNAP_HOST . ':' . SNAP_PORT,
        '-t', $root . '/public',
        $root . '/scripts/dev/web_router.php',
    ];
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('cannot start php -S');
    }
    for ($i = 0; $i < 100; $i++) {
        $sock = @fsockopen(SNAP_HOST, SNAP_PORT, $errno, $errstr, 0.2);
        if ($sock !== false) {
            fclose($sock);
            return ['proc' => $proc, 'log' => $log];
        }
        usleep(100000);
    }
    proc_terminate($proc);
    throw new RuntimeException('php -S did not start');
}

function snapStopServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
    // Wait for the port to be released before a second server binds it.
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen(SNAP_HOST, SNAP_PORT, $errno, $errstr, 0.1);
        if ($sock === false) {
            return;
        }
        fclose($sock);
        usleep(100000);
    }
}

/** @return array<string, int> ids needed by the scenarios */
function snapSeed(PDO $pdo): array
{
    if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'snap-%'")->fetchColumn() > 0) {
        throw new RuntimeException('database already seeded: run via scripts/dev/web-snapshot.sh (fresh shop_test)');
    }
    $insertUser = $pdo->prepare('INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, 1)');
    $insertUser->execute(['snap-user', SNAP_USER_EMAIL, password_hash(SNAP_USER_PASS, PASSWORD_DEFAULT), 'user']);
    $userId = (int) $pdo->lastInsertId();
    $insertUser->execute(['snap-other', SNAP_OTHER_EMAIL, password_hash('snap-pass-2', PASSWORD_DEFAULT), 'user']);
    $otherId = (int) $pdo->lastInsertId();
    $insertUser->execute([SNAP_ADMIN_NAME, 'snap-admin@example.test', password_hash(SNAP_ADMIN_PASS, PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();

    // Product 50 ships with stock 0; checkout needs every cart line in stock.
    $pdo->exec('UPDATE products SET stock = 10 WHERE id = 50');
    $secondId = (int) $pdo->query('SELECT MIN(id) FROM products WHERE stock >= 5 AND id <> 50')->fetchColumn();
    $outOfStockId = (int) $pdo->query('SELECT MIN(id) FROM products WHERE stock = 0 AND id <> 50')->fetchColumn();
    if ($secondId === 0 || $outOfStockId === 0) {
        throw new RuntimeException('catalog seed data not found (schema + migrations applied?)');
    }
    $insertCart = $pdo->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
    $insertCart->execute([$userId, 50, 1]);
    $cartFirst = (int) $pdo->lastInsertId();
    $insertCart->execute([$userId, $secondId, 2]);
    $cartSecond = (int) $pdo->lastInsertId();

    return [
        'user' => $userId, 'other' => $otherId, 'admin' => $adminId,
        'second' => $secondId, 'oos' => $outOfStockId,
        'cart_first' => $cartFirst, 'cart_second' => $cartSecond,
    ];
}

/** Product used by the admin delete scenario; no image, so no file is unlinked. */
function snapSeedDeletable(PDO $pdo): int
{
    $pdo->exec("INSERT INTO products (category_id, name, price, stock, description, image)
                VALUES (1, 'Snap Delete Me', 1000, 1, 'seeded for the delete scenario', NULL)");
    return (int) $pdo->lastInsertId();
}

/** Guest scenarios: storefront pages, admin redirects and the public API. */
function snapGuest(SnapRunner $s, array $ids): void
{
    $s->request('guest-home', 'guest', 'GET', '/');
    $s->request('guest-home-search', 'guest', 'GET', '/?search=áo');
    $s->request('guest-home-category', 'guest', 'GET', '/?category=1');
    $s->request('guest-product-50', 'guest', 'GET', '/product.php?id=50');
    $s->request('guest-product-oos', 'guest', 'GET', '/product.php?id=' . $ids['oos']);
    $s->request('guest-product-missing', 'guest', 'GET', '/product.php?id=999999');
    $s->request('guest-cart', 'guest', 'GET', '/cart.php');
    $s->request('guest-checkout', 'guest', 'GET', '/checkout.php');
    $s->request('guest-profile', 'guest', 'GET', '/profile.php');
    $s->request('guest-order-details', 'guest', 'GET', '/order_details.php?id=1');
    $s->request('guest-login-form', 'guest', 'GET', '/login.php');
    $s->request('guest-register-form', 'guest', 'GET', '/register.php');
    $s->request('guest-add-to-cart', 'guest', 'POST', '/add_to_cart.php', ['product_id' => 50, 'quantity' => 1, 'action' => 'add']);
    $s->request('guest-admin-index', 'guest', 'GET', '/admin/index.php');
    $s->request('guest-admin-products', 'guest', 'GET', '/admin/manage_products.php');
    $s->request('guest-admin-users', 'guest', 'GET', '/admin/manage_users.php');
    $s->request('guest-admin-orders', 'guest', 'GET', '/admin/manage_orders.php');
    $s->request('guest-admin-edit-product', 'guest', 'GET', '/admin/edit_product.php?id=50');
    $s->request('guest-admin-login-form', 'guest', 'GET', '/admin/login.php');

    // Every GET route of src/app/api/routes.php without credentials.
    $get = [
        'products-limit1' => '/api/products?limit=1',
        'products-search-sort' => '/api/products?search=áo thun&sort=price_asc&limit=5',
        'product-50' => '/api/products/50',
        'product-missing' => '/api/products/999999',
        'product-50-sizes' => '/api/products/50/sizes',
        'product-50-reviews' => '/api/products/50/reviews',
        'categories' => '/api/categories',
        'faq' => '/api/faq',
        'faq-search' => '/api/faq?search=đổi',
        'size-guide' => '/api/size-guide?height=170&weight=65',
        'outfit' => '/api/outfit',
        'outfit-product' => '/api/outfit?product_id=50',
        'knowledge-no-query' => '/api/knowledge/search',
        'chatbot-history' => '/api/chatbot/history',
        'auth-me' => '/api/auth/me',
        'cart' => '/api/cart',
        'orders' => '/api/orders',
        'order-1' => '/api/orders/1',
        'admin-dashboard' => '/api/admin/dashboard',
        'admin-products' => '/api/admin/products',
        'admin-orders' => '/api/admin/orders',
        'admin-users' => '/api/admin/users',
        'unknown-route' => '/api/does-not-exist',
    ];
    foreach ($get as $name => $path) {
        $s->request('guest-api-' . $name, 'guest', 'GET', $path);
    }
}

/** Register scenarios. */
function snapRegister(SnapRunner $s): void
{
    $s->request('register-mismatch', 'visitor', 'POST', '/register.php', [
        'fullname' => 'Snap New', 'email' => 'snap-new@example.test',
        'password' => 'snap-pass-3', 'confirm_password' => 'different',
    ]);
    $s->request('register-existing-email', 'visitor', 'POST', '/register.php', [
        'fullname' => 'Snap Dup', 'email' => SNAP_USER_EMAIL,
        'password' => 'snap-pass-3', 'confirm_password' => 'snap-pass-3',
    ]);
    $s->request('register-new-user', 'visitor', 'POST', '/register.php', [
        'fullname' => 'Snap New', 'email' => 'snap-new@example.test',
        'password' => 'snap-pass-3', 'confirm_password' => 'snap-pass-3',
    ]);
    $s->request('register-success-banner', 'visitor', 'GET', '/login.php?register_success=1');
}

/** Logged-in customer: pages, cart, review, checkout, REST API. */
function snapCustomer(SnapRunner $s, PDO $pdo, array $ids): void
{
    $u = 'customer';
    $s->request('customer-login-wrong', $u, 'POST', '/login.php', ['email' => SNAP_USER_EMAIL, 'password' => 'wrong-password']);
    $s->request('customer-login', $u, 'POST', '/login.php', ['email' => SNAP_USER_EMAIL, 'password' => SNAP_USER_PASS]);
    $s->request('customer-cart', $u, 'GET', '/cart.php');
    $s->request('customer-checkout', $u, 'GET', '/checkout.php');
    $s->request('customer-profile', $u, 'GET', '/profile.php');
    $s->request('customer-profile-status', $u, 'GET', '/profile.php?status=Đang giao');
    $s->request('customer-product-50', $u, 'GET', '/product.php?id=50');

    // State changes need the session's CSRF token, read from the form the product page renders.
    $token = $s->token($u, '/product.php?id=50');
    $cartDump = static fn(): string => json_encode($pdo->query('SELECT id, user_id, product_id, quantity, size FROM cart ORDER BY id')->fetchAll());
    $add = ['product_id' => 50, 'quantity' => 1, 'action' => 'add', 'selected_size' => 'M'];
    $s->noChange('customer-add-wrong-token', $cartDump, fn() => $s->request('customer-add-wrong-token', $u, 'POST', '/add_to_cart.php', $add + ['csrf_token' => 'not-the-token']));
    $s->request('customer-add-50', $u, 'POST', '/add_to_cart.php', $add + ['csrf_token' => $token]);
    $s->request('customer-buy-now-50', $u, 'POST', '/add_to_cart.php', ['product_id' => 50, 'quantity' => 1, 'action' => 'buy_now', 'csrf_token' => $token]);
    $s->request('customer-add-out-of-stock', $u, 'POST', '/add_to_cart.php', ['product_id' => $ids['oos'], 'quantity' => 1, 'action' => 'add', 'csrf_token' => $token]);
    $s->request('customer-add-in-stock-other', $u, 'POST', '/add_to_cart.php', ['product_id' => $ids['second'], 'quantity' => 1, 'action' => 'add', 'csrf_token' => $token]);
    $s->request('customer-add-get-method', $u, 'GET', '/add_to_cart.php');
    $s->request('customer-cart-after-add', $u, 'GET', '/cart.php');

    $first = $ids['cart_first'];
    $update = static fn(string $value, string $type): array => ['id' => $first, 'value' => $value, 'type' => $type];
    $s->noChange('customer-update-qty-get', $cartDump, fn() => $s->request('customer-update-qty-get', $u, 'GET', '/update_cart.php?id=' . $first . '&value=3&type=qty'));
    $s->noChange('customer-update-qty-wrong-token', $cartDump, fn() => $s->request('customer-update-qty-wrong-token', $u, 'POST', '/update_cart.php', $update('3', 'qty') + ['csrf_token' => 'not-the-token']));
    $s->request('customer-update-qty', $u, 'POST', '/update_cart.php', $update('3', 'qty') + ['csrf_token' => $token]);
    $s->noChange('customer-update-size-get', $cartDump, fn() => $s->request('customer-update-size-get', $u, 'GET', '/update_cart.php?id=' . $first . '&value=L&type=size'));
    $s->noChange('customer-update-size-wrong-token', $cartDump, fn() => $s->request('customer-update-size-wrong-token', $u, 'POST', '/update_cart.php', $update('L', 'size') + ['csrf_token' => 'not-the-token']));
    $s->request('customer-update-size', $u, 'POST', '/update_cart.php', $update('M', 'size') + ['csrf_token' => $token]);
    $s->request('customer-cart-after-update', $u, 'GET', '/cart.php');
    $s->noChange('customer-remove-line-get', $cartDump, fn() => $s->request('customer-remove-line-get', $u, 'GET', '/cart.php?remove=' . $ids['cart_second']));
    $s->noChange('customer-remove-line-wrong-token', $cartDump, fn() => $s->request('customer-remove-line-wrong-token', $u, 'POST', '/cart.php', ['remove' => $ids['cart_second'], 'csrf_token' => 'not-the-token']));
    $s->request('customer-remove-line', $u, 'POST', '/cart.php', ['remove' => $ids['cart_second'], 'csrf_token' => $token]);
    $s->request('customer-cart-after-remove', $u, 'GET', '/cart.php');

    $reviewDump = static fn(): string => json_encode($pdo->query('SELECT product_id, user_id, rating, comment FROM reviews ORDER BY id')->fetchAll());
    $review = ['product_id' => 50, 'rating' => 5, 'comment' => 'Snap review: chất liệu tốt'];
    $s->noChange('customer-review-wrong-token', $reviewDump, fn() => $s->request('customer-review-wrong-token', $u, 'POST', '/submit_review.php', $review + ['csrf_token' => 'not-the-token']));
    $s->request('customer-review', $u, 'POST', '/submit_review.php', $review + ['csrf_token' => $token]);
    $s->request('customer-review-invalid', $u, 'POST', '/submit_review.php', ['product_id' => 50, 'rating' => 9, 'comment' => '', 'csrf_token' => $token]);
    $s->request('customer-product-50-after-review', $u, 'GET', '/product.php?id=50');

    $orderDump = static fn(): string => json_encode([
        $pdo->query('SELECT id, user_id, total_price, status FROM orders ORDER BY id')->fetchAll(),
        $pdo->query('SELECT id, stock FROM products ORDER BY id')->fetchAll(),
        $pdo->query('SELECT id, product_id, quantity, size FROM cart ORDER BY id')->fetchAll(),
    ]);
    $shipping = ['full_name' => 'Snap User', 'phone' => '0900000000', 'address' => '1 Snap Street', 'note' => 'snapshot'];
    $checkoutToken = $s->token($u, '/checkout.php');
    $s->noChange('customer-process-checkout-wrong-token', $orderDump, fn() => $s->request('customer-process-checkout-wrong-token', $u, 'POST', '/process_checkout.php', $shipping + ['csrf_token' => 'not-the-token']));
    $s->noChange('customer-process-checkout-get', $orderDump, fn() => $s->request('customer-process-checkout-get', $u, 'GET', '/process_checkout.php?' . http_build_query($shipping + ['csrf_token' => $checkoutToken])));
    $s->request('customer-process-checkout', $u, 'POST', '/process_checkout.php', $shipping + ['csrf_token' => $checkoutToken]);
    $s->request('customer-checkout-empty-cart', $u, 'GET', '/checkout.php');
    $orderId = (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
    $s->request('customer-profile-after-order', $u, 'GET', '/profile.php');
    $s->request('customer-profile-after-order-pending', $u, 'GET', '/profile.php?status=pending');
    // order_details.php reads the non-existent table order_details: 500 today.
    $s->request('customer-order-details', $u, 'GET', '/order_details.php?id=' . $orderId);
    $s->request('customer-order-details-foreign', $u, 'GET', '/order_details.php?id=1');
    $s->request('customer-logout', $u, 'GET', '/logout.php');
    $s->request('customer-cart-after-logout', $u, 'GET', '/cart.php');

    // REST API with the token from POST /api/auth/login.
    $api = 'customer-api';
    $s->api('customer-api-login-wrong', $api, 'POST', '/api/auth/login', ['email' => SNAP_USER_EMAIL, 'password' => 'nope']);
    $login = $s->api('customer-api-login', $api, 'POST', '/api/auth/login', ['email' => SNAP_USER_EMAIL, 'password' => SNAP_USER_PASS]);
    $token = is_string($login['token'] ?? null) ? $login['token'] : null;
    if ($token === null) {
        $s->failures[] = 'customer-api-login: no token in response';
        return;
    }
    $s->api('customer-api-me', $api, 'GET', '/api/auth/me', null, $token);
    $s->api('customer-api-cart-empty', $api, 'GET', '/api/cart', null, $token);
    $s->api('customer-api-cart-add', $api, 'POST', '/api/cart', ['product_id' => $ids['second'], 'quantity' => 1, 'size' => 'M'], $token);
    $s->api('customer-api-cart-add-invalid', $api, 'POST', '/api/cart', ['product_id' => 0], $token);
    $s->api('customer-api-cart', $api, 'GET', '/api/cart', null, $token);
    $s->api('customer-api-orders', $api, 'GET', '/api/orders', null, $token);
    $s->api('customer-api-order-detail', $api, 'GET', '/api/orders/' . $orderId, null, $token);
    $s->api('customer-api-review', $api, 'POST', '/api/products/50/reviews', ['rating' => 4, 'comment' => 'Snap API review'], $token);
    $s->api('customer-api-admin-forbidden', $api, 'GET', '/api/admin/dashboard', null, $token);
    $s->api('customer-api-order-create', $api, 'POST', '/api/orders', null, $token);
    // Reviews sort by created_at (one-second resolution): space them out so a
    // slow or fast run cannot reorder two reviews written in the same second.
    $pdo->exec("UPDATE reviews SET created_at = TIMESTAMPADD(SECOND, id, '2026-01-01 00:00:00')");
    $s->api('customer-api-reviews-after', $api, 'GET', '/api/products/50/reviews');

    // Cart writes: add, PUT (quantity, size), DELETE, each followed by a read.
    $added = $s->api('customer-api-cart-add-again', $api, 'POST', '/api/cart', ['product_id' => $ids['second'], 'quantity' => 1, 'size' => 'M'], $token);
    $cartId = (int) ($added['cart_id'] ?? 0);
    if ($cartId === 0) {
        $s->failures[] = 'customer-api-cart-add-again: no cart_id in response';
        return;
    }
    $s->api('customer-api-cart-put-quantity', $api, 'PUT', '/api/cart/' . $cartId, ['quantity' => 2], $token);
    $s->api('customer-api-cart-after-put-quantity', $api, 'GET', '/api/cart', null, $token);
    $s->api('customer-api-cart-put-size', $api, 'PUT', '/api/cart/' . $cartId, ['size' => 'l'], $token);
    $s->api('customer-api-cart-put-invalid', $api, 'PUT', '/api/cart/' . $cartId, ['quantity' => 0], $token);
    $s->api('customer-api-cart-put-missing', $api, 'PUT', '/api/cart/999999', ['quantity' => 1], $token);
    $s->api('customer-api-cart-after-put-size', $api, 'GET', '/api/cart', null, $token);
    $s->api('customer-api-cart-delete', $api, 'DELETE', '/api/cart/' . $cartId, null, $token);
    $s->api('customer-api-cart-delete-missing', $api, 'DELETE', '/api/cart/' . $cartId, null, $token);
    $s->api('customer-api-cart-after-delete', $api, 'GET', '/api/cart', null, $token);

    // Account writes: register (invalid, new, duplicate), login rotation, logout.
    $reg = 'new-api-user';
    $s->api('api-register-invalid', $reg, 'POST', '/api/auth/register', ['email' => 'not-an-email', 'password' => 'snap-pass-4', 'username' => 'x']);
    $created = $s->api('api-register-new', $reg, 'POST', '/api/auth/register', [
        'username' => 'snap-api-new', 'email' => 'snap-api-new@example.test', 'password' => 'snap-pass-4',
    ]);
    $s->api('api-register-duplicate', $reg, 'POST', '/api/auth/register', [
        'username' => 'snap-api-dup', 'email' => SNAP_USER_EMAIL, 'password' => 'snap-pass-4',
    ]);
    $newToken = is_string($created['token'] ?? null) ? $created['token'] : null;
    if ($newToken === null) {
        $s->failures[] = 'api-register-new: no token in response';
        return;
    }
    $s->api('api-register-new-me', $reg, 'GET', '/api/auth/me', null, $newToken);
    // Logging in rotates the token: the registration token stops working.
    $login = $s->api('api-register-new-login', $reg, 'POST', '/api/auth/login', ['email' => 'snap-api-new@example.test', 'password' => 'snap-pass-4']);
    $s->api('api-register-token-rotated-me', $reg, 'GET', '/api/auth/me', null, $newToken);
    $loginToken = is_string($login['token'] ?? null) ? $login['token'] : null;
    if ($loginToken === null) {
        $s->failures[] = 'api-register-new-login: no token in response';
        return;
    }
    $s->api('api-logout', $reg, 'POST', '/api/auth/logout', null, $loginToken);
    $s->api('api-logout-me-after', $reg, 'GET', '/api/auth/me', null, $loginToken);
}

/** Admin stored in the database: storefront-less admin pages and REST admin API. */
function snapDbAdmin(SnapRunner $s, PDO $pdo, array $ids): void
{
    $a = 'dbadmin';
    $s->request('dbadmin-login-wrong', $a, 'POST', '/admin/login.php', ['username' => SNAP_ADMIN_NAME, 'password' => 'wrong', 'login' => '1']);
    $s->request('dbadmin-login', $a, 'POST', '/admin/login.php', ['username' => SNAP_ADMIN_NAME, 'password' => SNAP_ADMIN_PASS, 'login' => '1']);
    $s->request('dbadmin-index', $a, 'GET', '/admin/index.php');
    $s->request('dbadmin-manage-products', $a, 'GET', '/admin/manage_products.php');
    $s->request('dbadmin-manage-orders', $a, 'GET', '/admin/manage_orders.php');
    $s->request('dbadmin-manage-users', $a, 'GET', '/admin/manage_users.php');
    $s->request('dbadmin-edit-product-50', $a, 'GET', '/admin/edit_product.php?id=50');
    $s->request('dbadmin-edit-product-missing', $a, 'GET', '/admin/edit_product.php?id=999999');
    $s->request('dbadmin-add-product-form', $a, 'GET', '/admin/add_product.php');

    // State changes need the session's CSRF token, read from the form the admin page renders.
    $token = $s->token($a, '/admin/manage_orders.php');
    $bad = 'not-the-token';
    $orderId = (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
    $orderDump = static fn(): string => json_encode($pdo->query('SELECT id, user_id, total_price, status FROM orders ORDER BY id')->fetchAll());
    $status = ['update_status' => '1', 'order_id' => $orderId, 'status' => 'Đang giao'];
    $s->noChange('dbadmin-order-status-wrong-token', $orderDump, fn() => $s->request('dbadmin-order-status-wrong-token', $a, 'POST', '/admin/manage_orders.php', $status + ['csrf_token' => $bad]));
    $s->request('dbadmin-order-status', $a, 'POST', '/admin/manage_orders.php', $status + ['csrf_token' => $token]);
    $s->request('dbadmin-manage-orders-after', $a, 'GET', '/admin/manage_orders.php');

    $other = $ids['other'];
    $userDump = static fn(): string => json_encode($pdo->query('SELECT id, username, role, status FROM users ORDER BY id')->fetchAll());
    $act = static fn(array $fields): array => $fields + ['id' => $other, 'csrf_token' => $token];
    $s->noChange('dbadmin-user-lock-get', $userDump, fn() => $s->request('dbadmin-user-lock-get', $a, 'GET', '/admin/manage_users.php?action=lock&id=' . $other));
    $s->noChange('dbadmin-user-lock-wrong-token', $userDump, fn() => $s->request('dbadmin-user-lock-wrong-token', $a, 'POST', '/admin/manage_users.php', ['action' => 'lock', 'id' => $other, 'csrf_token' => $bad]));
    $s->request('dbadmin-user-lock', $a, 'POST', '/admin/manage_users.php', $act(['action' => 'lock']));
    $s->request('dbadmin-users-after-lock', $a, 'GET', '/admin/manage_users.php?msg=locked');
    $s->noChange('dbadmin-user-unlock-get', $userDump, fn() => $s->request('dbadmin-user-unlock-get', $a, 'GET', '/admin/manage_users.php?action=unlock&id=' . $other));
    $s->noChange('dbadmin-user-unlock-wrong-token', $userDump, fn() => $s->request('dbadmin-user-unlock-wrong-token', $a, 'POST', '/admin/manage_users.php', ['action' => 'unlock', 'id' => $other, 'csrf_token' => $bad]));
    $s->request('dbadmin-user-unlock', $a, 'POST', '/admin/manage_users.php', $act(['action' => 'unlock']));
    $s->noChange('dbadmin-user-toggle-role-get', $userDump, fn() => $s->request('dbadmin-user-toggle-role-get', $a, 'GET', '/admin/manage_users.php?toggle_role=admin&id=' . $other));
    $s->noChange('dbadmin-user-toggle-role-wrong-token', $userDump, fn() => $s->request('dbadmin-user-toggle-role-wrong-token', $a, 'POST', '/admin/manage_users.php', ['toggle_role' => 'admin', 'id' => $other, 'csrf_token' => $bad]));
    $s->request('dbadmin-user-toggle-role', $a, 'POST', '/admin/manage_users.php', $act(['toggle_role' => 'admin']));
    $s->request('dbadmin-users-after-role', $a, 'GET', '/admin/manage_users.php?msg=updated');

    // No file part: the "without an image" path. multipart, as the real forms are.
    $productDump = static fn(): string => json_encode($pdo->query('SELECT id, name, price, stock, description, image, category_id, subcategory_id, style_tags FROM products ORDER BY id')->fetchAll());
    $edit = [
        'update_product' => '1', 'name' => 'Áo Thun Cotton Basic Trắng', 'price' => '185000', 'stock' => '10',
        'description' => 'Snapshot edit', 'category_id' => '1', 'subcategory_id' => '0', 'style_tags' => ['basic', 'dichoi'],
    ];
    $s->noChange('dbadmin-edit-product-get', $productDump, fn() => $s->request('dbadmin-edit-product-get', $a, 'GET', '/admin/edit_product.php?id=50&' . http_build_query($edit + ['csrf_token' => $token])));
    $s->noChange('dbadmin-edit-product-wrong-token', $productDump, fn() => $s->request('dbadmin-edit-product-wrong-token', $a, 'POST', '/admin/edit_product.php?id=50', $edit + ['csrf_token' => $bad], [], true));
    $s->request('dbadmin-edit-product-post', $a, 'POST', '/admin/edit_product.php?id=50', $edit + ['csrf_token' => $token], [], true);
    $s->request('dbadmin-edit-product-after', $a, 'GET', '/admin/edit_product.php?id=50');
    $add = ['add_product' => '1', 'name' => 'Snap Added', 'price' => '99000', 'category_id' => '1', 'style_tags' => ['basic']];
    $s->noChange('dbadmin-add-product-wrong-token', $productDump, fn() => $s->request('dbadmin-add-product-wrong-token', $a, 'POST', '/admin/add_product.php', $add + ['csrf_token' => $bad], [], true));
    $s->request('dbadmin-add-product-no-image', $a, 'POST', '/admin/add_product.php', $add + ['csrf_token' => $token], [], true);
    $list = [
        'add_product' => '1', 'name' => 'Snap Added', 'price' => '99000', 'stock' => '3', 'category_id' => '1',
        'subcategory_id' => '0', 'description' => 'no image',
    ];
    $s->noChange('dbadmin-manage-products-add-wrong-token', $productDump, fn() => $s->request('dbadmin-manage-products-add-wrong-token', $a, 'POST', '/admin/manage_products.php', $list + ['csrf_token' => $bad], [], true));
    $s->request('dbadmin-manage-products-add-no-image', $a, 'POST', '/admin/manage_products.php', $list + ['csrf_token' => $token], [], true);

    $deletable = snapSeedDeletable($pdo);
    $s->noChange('dbadmin-delete-product-get', $productDump, fn() => $s->request('dbadmin-delete-product-get', $a, 'GET', '/admin/manage_products.php?delete_id=' . $deletable));
    $s->noChange('dbadmin-delete-product-wrong-token', $productDump, fn() => $s->request('dbadmin-delete-product-wrong-token', $a, 'POST', '/admin/manage_products.php', ['delete_id' => $deletable, 'csrf_token' => $bad]));
    $s->request('dbadmin-delete-product', $a, 'POST', '/admin/manage_products.php', ['delete_id' => $deletable, 'csrf_token' => $token]);
    $s->request('dbadmin-manage-products-after', $a, 'GET', '/admin/manage_products.php?msg=deleted');
    $s->request('dbadmin-index-after', $a, 'GET', '/admin/index.php');
    $s->request('dbadmin-logout', $a, 'GET', '/admin/logout.php');
    $s->request('dbadmin-index-after-logout', $a, 'GET', '/admin/index.php');

    // REST admin API with an admin token.
    $api = 'dbadmin-api';
    $login = $s->api('dbadmin-api-login', $api, 'POST', '/api/auth/login', ['email' => SNAP_ADMIN_NAME, 'password' => SNAP_ADMIN_PASS]);
    $token = is_string($login['token'] ?? null) ? $login['token'] : null;
    if ($token === null) {
        $s->failures[] = 'dbadmin-api-login: no token in response';
        return;
    }
    foreach (['dashboard', 'products', 'orders', 'users'] as $name) {
        $s->api('dbadmin-api-' . $name, $api, 'GET', '/api/admin/' . $name, null, $token);
    }
    $s->api('dbadmin-api-order-status', $api, 'PUT', '/api/admin/orders/' . $orderId, ['status' => 'Đã hoàn thành'], $token);
    $s->api('dbadmin-api-order-status-invalid', $api, 'PUT', '/api/admin/orders/' . $orderId, ['status' => 'bogus'], $token);
    $s->api('dbadmin-api-orders-after', $api, 'GET', '/api/admin/orders', null, $token);
    $s->api('dbadmin-api-dashboard-after', $api, 'GET', '/api/admin/dashboard', null, $token);

    // Product writes (JSON only, no upload), each followed by the admin list.
    $s->api('dbadmin-api-product-create-invalid', $api, 'POST', '/api/admin/products', ['name' => '', 'price' => 0], $token);
    $created = $s->api('dbadmin-api-product-create', $api, 'POST', '/api/admin/products', [
        'name' => 'Snap API Product', 'price' => 123000, 'stock' => 7, 'description' => 'created through the API',
        'category_id' => 1, 'style_tags' => ['basic', 'dichoi'],
    ], $token);
    $newId = (int) ($created['product_id'] ?? 0);
    if ($newId === 0) {
        $s->failures[] = 'dbadmin-api-product-create: no product_id in response';
        return;
    }
    $s->api('dbadmin-api-products-after-create', $api, 'GET', '/api/admin/products', null, $token);
    $s->api('dbadmin-api-product-update-empty', $api, 'PUT', '/api/admin/products/' . $newId, [], $token);
    $s->api('dbadmin-api-product-update', $api, 'PUT', '/api/admin/products/' . $newId, [
        'name' => 'Snap API Product v2', 'price' => 125000, 'stock' => 3, 'description' => 'updated through the API',
        'style_tags' => ['thethao'],
    ], $token);
    $s->api('dbadmin-api-product-update-missing', $api, 'PUT', '/api/admin/products/999999', ['name' => 'nope'], $token);
    $s->api('dbadmin-api-products-after-update', $api, 'GET', '/api/admin/products', null, $token);
    $deletable = snapSeedDeletable($pdo);
    $s->api('dbadmin-api-product-delete', $api, 'DELETE', '/api/admin/products/' . $deletable, null, $token);
    $s->api('dbadmin-api-product-delete-missing', $api, 'DELETE', '/api/admin/products/' . $deletable, null, $token);
    $s->api('dbadmin-api-products-after-delete', $api, 'GET', '/api/admin/products', null, $token);

    // User writes: lock, unlock, role, plus the rejected cases.
    $other = $ids['other'];
    $s->api('dbadmin-api-user-lock', $api, 'PUT', '/api/admin/users/' . $other, ['action' => 'lock'], $token);
    $s->api('dbadmin-api-users-after-lock', $api, 'GET', '/api/admin/users', null, $token);
    $s->api('dbadmin-api-user-unlock', $api, 'PUT', '/api/admin/users/' . $other, ['action' => 'unlock'], $token);
    $s->api('dbadmin-api-users-after-unlock', $api, 'GET', '/api/admin/users', null, $token);
    $s->api('dbadmin-api-user-role-user', $api, 'PUT', '/api/admin/users/' . $other, ['action' => 'toggle_role', 'role' => 'user'], $token);
    $s->api('dbadmin-api-user-role-invalid', $api, 'PUT', '/api/admin/users/' . $other, ['action' => 'toggle_role', 'role' => 'root'], $token);
    $s->api('dbadmin-api-user-self', $api, 'PUT', '/api/admin/users/' . $ids['admin'], ['action' => 'lock'], $token);
    $s->api('dbadmin-api-user-missing', $api, 'PUT', '/api/admin/users/999999', ['action' => 'lock'], $token);
    $s->api('dbadmin-api-users-after-role', $api, 'GET', '/api/admin/users', null, $token);

    // Bad and missing tokens on admin routes.
    $s->api('dbadmin-api-bad-token', $api, 'GET', '/api/admin/dashboard', null, 'not-a-valid-token');
    $s->api('dbadmin-api-bad-token-write', $api, 'PUT', '/api/admin/orders/' . $orderId, ['status' => 'Đã hủy'], 'not-a-valid-token');
    $s->api('dbadmin-api-no-token-write', $api, 'DELETE', '/api/admin/products/' . $newId);
}

/** Admin whose credentials come from the environment (second server only). */
function snapEnvAdmin(SnapRunner $s): void
{
    $a = 'envadmin';
    $s->request('admin-env-login-wrong', $a, 'POST', '/login.php', ['email' => SNAP_ENV_ADMIN_EMAIL, 'password' => 'wrong']);
    $s->request('admin-env-login', $a, 'POST', '/login.php', ['email' => SNAP_ENV_ADMIN_EMAIL, 'password' => SNAP_ENV_ADMIN_PASS]);
    $s->request('admin-env-index', $a, 'GET', '/admin/index.php');
    $s->request('admin-env-manage-users', $a, 'GET', '/admin/manage_users.php');
    $s->request('admin-env-add-to-cart', $a, 'POST', '/add_to_cart.php', [
        'product_id' => 50, 'quantity' => 1, 'action' => 'add', 'csrf_token' => $s->token($a, '/product.php?id=50'),
    ]);
    $s->request('admin-env-cart', $a, 'GET', '/cart.php');

    $b = 'envadmin-panel';
    $s->request('admin-env-panel-login-wrong', $b, 'POST', '/admin/login.php', ['username' => SNAP_ENV_ADMIN_USER, 'password' => 'wrong', 'login' => '1']);
    $s->request('admin-env-panel-login', $b, 'POST', '/admin/login.php', ['username' => SNAP_ENV_ADMIN_USER, 'password' => SNAP_ENV_ADMIN_PASS, 'login' => '1']);
    $s->request('admin-env-panel-index', $b, 'GET', '/admin/index.php');
    $s->request('admin-env-panel-manage-users', $b, 'GET', '/admin/manage_users.php');

    // A customer login in the same browser must drop the admin flag: the admin pages send the customer away.
    $c = 'envadmin-then-customer';
    $s->request('admin-env-then-customer-login-admin', $c, 'POST', '/login.php', ['email' => SNAP_ENV_ADMIN_EMAIL, 'password' => SNAP_ENV_ADMIN_PASS]);
    $s->request('admin-env-then-customer-login', $c, 'POST', '/login.php', ['email' => SNAP_USER_EMAIL, 'password' => SNAP_USER_PASS]);
    $s->request('admin-env-then-customer-index', $c, 'GET', '/admin/index.php');
}

/**
 * Built-in sanity checks; a harness bug must not produce a plausible baseline.
 *
 * @return list<string> problems
 */
function snapAssertions(SnapRunner $runner): array
{
    $problems = [];
    $guestCart = $runner->results['guest-cart'] ?? null;
    if ($guestCart === null || $guestCart['location'] !== 'login.php?error=vui_long_dang_nhap') {
        $problems[] = 'guest-cart must redirect to login.php?error=vui_long_dang_nhap';
    }
    $stale = $runner->results['admin-env-then-customer-index'] ?? null;
    if ($stale === null || $stale['location'] !== 'login.php') {
        $problems[] = 'admin-env-then-customer-index must redirect to login.php (the stale admin flag is dropped)';
    }
    foreach (['customer-api-login', 'dbadmin-api-login'] as $name) {
        if (($runner->results[$name]['status'] ?? 0) !== 200) {
            $problems[] = "$name must return 200 with an api token";
        }
    }
    $errors = [];
    foreach ($runner->results as $name => $result) {
        if ($result['status'] === 500) {
            $errors[] = $name;
        }
    }
    if ($errors !== [] && $errors !== ['customer-order-details']) {
        $problems[] = 'unexpected status 500: ' . implode(', ', $errors) . ' (only customer-order-details may)';
    }
    return $problems;
}

function snapMain(array $argv): int
{
    $outDir = null;
    $outputBuffering = '4096';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--output-buffering=')) {
            $outputBuffering = substr($arg, strlen('--output-buffering='));
        } elseif ($outDir === null) {
            $outDir = rtrim($arg, '/');
        }
    }
    if ($outDir === null) {
        fwrite(STDERR, "usage: web_snapshot.php <outdir> [--output-buffering=4096]\n");
        return 2;
    }
    $root = dirname(__DIR__, 2);
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
        fwrite(STDERR, "cannot create $outDir\n");
        return 2;
    }
    foreach (glob($outDir . '/*.txt') ?: [] as $old) {
        unlink($old);
    }

    $host = (string) getenv('DB_HOST');
    $name = (string) getenv('DB_NAME');
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $ids = snapSeed($pdo);

    $tmp = sys_get_temp_dir() . '/web-snapshot-' . getmypid();
    mkdir($tmp, 0775, true);
    $runner = new SnapRunner($outDir, 'http://' . SNAP_HOST . ':' . SNAP_PORT);
    $logs = [];
    $phases = [
        // Server 1: no ADMIN_* variables.
        ['name' => 'main', 'env' => [], 'run' => static function () use ($runner, $pdo, $ids): void {
            snapGuest($runner, $ids);
            snapRegister($runner);
            snapCustomer($runner, $pdo, $ids);
            snapDbAdmin($runner, $pdo, $ids);
        }],
        // Server 2: environment administrator, scenarios admin-env-*.
        ['name' => 'env', 'env' => [
            'ADMIN_EMAIL' => SNAP_ENV_ADMIN_EMAIL, 'ADMIN_PASSWORD' => SNAP_ENV_ADMIN_PASS, 'ADMIN_USER' => SNAP_ENV_ADMIN_USER,
        ], 'run' => static function () use ($runner): void {
            snapEnvAdmin($runner);
        }],
    ];
    $exit = 0;
    try {
        foreach ($phases as $i => $phase) {
            $cacheDir = $tmp . '/phase' . $i;
            mkdir($cacheDir, 0775, true);
            $server = snapStartServer($root, snapServerEnv($phase['env'] + ['TMPDIR' => $cacheDir]), $outputBuffering);
            $logs[] = $server['log'];
            $runner->resetActors();
            try {
                $phase['run']();
            } finally {
                snapStopServer($server['proc']);
                // Written even when the phase threw, so the log explains why.
                $raw = is_file($server['log']) ? (string) file_get_contents($server['log']) : '';
                file_put_contents($outDir . '/zz-server-log-' . $phase['name'] . '.txt', snapNormaliseLog($raw));
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'web_snapshot: ' . $e->getMessage() . "\n");
        $exit = 1;
    }
    foreach (snapAssertions($runner) as $problem) {
        $runner->failures[] = 'assertion: ' . $problem;
    }
    foreach ($runner->failures as $failure) {
        fwrite(STDERR, "FAILED scenario: $failure\n");
        $exit = 1;
    }
    if ($exit !== 0) {
        foreach ($logs as $log) {
            if (is_file($log)) {
                fwrite(STDERR, "--- $log (tail)\n" . implode('', array_slice(file($log) ?: [], -20)));
            }
        }
    }
    fwrite(STDERR, sprintf("web_snapshot: %d scenarios written to %s\n", $runner->seq(), $outDir));
    return $exit;
}

exit(snapMain($argv));
