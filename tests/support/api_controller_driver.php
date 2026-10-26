<?php

declare(strict_types=1);

/**
 * Runs one REST controller script (src/app/api/controllers/<controller>) in its own PHP process against an in-memory
 * SQLite shop, the way public/api/index.php would, and prints "<body>\n@@STATUS=<http code>" when the script exits.
 * A subprocess is needed because every controller ends in jsonResponse(), which calls exit.
 *
 * Usage: php api_controller_driver.php '<json scenario>'
 *   controller   path under src/app/api/controllers, e.g. "Cart/add.php"
 *   route_params e.g. {"id": 1}            body  the JSON request body (array)        query  $_GET
 *   setup        SQL statements run after the fixture, e.g. DROP TABLE cart
 *
 * helpers.php is evaluated without its bootstrap.php line (that opens the MariaDB connection) and with php://input
 * pointed at the scenario body (php://input is empty on the CLI); authenticate() is a stub for user 5.
 * SQLite only: this never touches shop_test or shop_db.
 */
$scenario = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);

require_once dirname(__DIR__) . '/bootstrap.php';

$bodyFile = tempnam(sys_get_temp_dir(), 'apibody');
file_put_contents($bodyFile, json_encode($scenario['body'] ?? new stdClass()));

$helpers = (string) file_get_contents(ROOT_DIR . '/src/core/http/helpers.php');
$helpers = preg_replace('/^require_once dirname\(__DIR__\) \. \'\/bootstrap\.php\';\R/m', '', $helpers, 1, $stripped);
if ($stripped !== 1 || !str_contains($helpers, "'php://input'")) {
    fwrite(STDERR, "driver out of date: helpers.php changed shape\n");
    exit(3);
}
eval('?>' . str_replace("'php://input'", var_export($bodyFile, true), $helpers));

function authenticate(): array
{
    return ['id' => 5, 'status' => 1, 'role' => 'user'];
}

$pdo = SqliteShop::pdo();
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total_price REAL, status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER,
    quantity INTEGER, price REAL)');
SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 5, '');
$pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 1, 'M')");
$pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (3, 5, 150000, 'pending')");
$pdo->exec('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (3, 7, 1, 150000)');
foreach ($scenario['setup'] ?? [] as $sql) {
    $pdo->exec($sql);
}

$GLOBALS['route_params'] = $scenario['route_params'] ?? [];
$_GET = $scenario['query'] ?? [];
$_SERVER['REQUEST_METHOD'] = $scenario['method'] ?? 'GET';

register_shutdown_function(static function () use ($bodyFile): void {
    unlink($bodyFile);
    echo "\n@@STATUS=" . http_response_code();
});

require ROOT_DIR . '/src/app/api/controllers/' . $scenario['controller'];
