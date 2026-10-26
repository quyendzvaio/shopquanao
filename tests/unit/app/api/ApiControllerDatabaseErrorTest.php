<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cart and order REST controllers answer a database failure with a generic 500 (databaseErrorResponse), never a
 * 4xx/409 domain error and never the SQL/driver text, while domain refusals keep their codes and messages.
 * Each controller runs in a subprocess (tests/support/api_controller_driver.php) because it ends in exit.
 */
final class ApiControllerDatabaseErrorTest extends TestCase
{
    private const GENERIC = 'Lỗi hệ thống, vui lòng thử lại sau.';

    /** @return array{0:int,1:array<string,mixed>,2:string} status, decoded body, stderr */
    private function request(array $scenario): array
    {
        $process = proc_open(
            [PHP_BINARY, ROOT_DIR . '/tests/support/api_controller_driver.php', json_encode($scenario)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertMatchesRegularExpression('/\n@@STATUS=(\d+)$/', $stdout, "driver output: $stdout\n$stderr");
        [$body, $status] = explode("\n@@STATUS=", $stdout);

        return [(int) $status, json_decode($body, true) ?? [], $stderr];
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> scenario, the dropped table */
    public static function databaseFailures(): array
    {
        return [
            'cart add' => [['controller' => 'Cart/add.php', 'method' => 'POST', 'body' => ['product_id' => 7, 'quantity' => 1], 'setup' => ['DROP TABLE cart']], 'cart'],
            'cart update' => [['controller' => 'Cart/update.php', 'method' => 'PUT', 'route_params' => ['id' => 1], 'body' => ['quantity' => 2], 'setup' => ['DROP TABLE cart']], 'cart'],
            'cart remove' => [['controller' => 'Cart/remove.php', 'method' => 'DELETE', 'route_params' => ['id' => 1], 'setup' => ['DROP TABLE cart']], 'cart'],
            'cart list' => [['controller' => 'Cart/list.php', 'setup' => ['DROP TABLE cart']], 'cart'],
            'order create' => [['controller' => 'Orders/create.php', 'method' => 'POST', 'setup' => ['DROP TABLE order_items']], 'order_items'],
            'order detail' => [['controller' => 'Orders/detail.php', 'route_params' => ['id' => 3], 'setup' => ['DROP TABLE orders']], 'orders'],
            'order list' => [['controller' => 'Orders/list.php', 'setup' => ['DROP TABLE orders']], 'orders'],
        ];
    }

    #[DataProvider('databaseFailures')]
    public function testADatabaseFailureIsAGeneric500(array $scenario, string $table): void
    {
        [$status, $body, $log] = $this->request($scenario);

        $this->assertSame(500, $status);
        $this->assertSame(['error' => true, 'message' => self::GENERIC], $body);
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $json);
        $this->assertStringNotContainsString($table, $json);
        $this->assertStringNotContainsString('not found', $json);
        $this->assertStringContainsString('API database error: ', $log);
        $this->assertStringContainsString("no such table: $table", $log);
    }

    /** @return array<string,array{0:array<string,mixed>,1:int,2:string|null}> scenario, status, message (null = a success body) */
    public static function domainOutcomes(): array
    {
        return [
            'cart add: unknown product' => [['controller' => 'Cart/add.php', 'method' => 'POST', 'body' => ['product_id' => 99, 'quantity' => 1]], 404, 'Product not found'],
            'cart add: too much for the stock' => [['controller' => 'Cart/add.php', 'method' => 'POST', 'body' => ['product_id' => 7, 'quantity' => 99]], 409, 'Insufficient product stock'],
            'cart add: no product_id' => [['controller' => 'Cart/add.php', 'method' => 'POST', 'body' => ['quantity' => 1]], 400, 'product_id and a positive quantity are required'],
            'cart add: ok' => [['controller' => 'Cart/add.php', 'method' => 'POST', 'body' => ['product_id' => 7, 'quantity' => 1]], 201, null],
            'cart update: unknown line' => [['controller' => 'Cart/update.php', 'method' => 'PUT', 'route_params' => ['id' => 99], 'body' => ['quantity' => 2]], 404, 'Cart item not found'],
            'cart update: bad quantity' => [['controller' => 'Cart/update.php', 'method' => 'PUT', 'route_params' => ['id' => 1], 'body' => ['quantity' => 0]], 400, 'quantity must be positive'],
            'cart remove: unknown line' => [['controller' => 'Cart/remove.php', 'method' => 'DELETE', 'route_params' => ['id' => 99]], 404, 'Cart item not found'],
            'cart list: ok' => [['controller' => 'Cart/list.php'], 200, null],
            'order create: empty cart' => [['controller' => 'Orders/create.php', 'method' => 'POST', 'setup' => ['DELETE FROM cart']], 400, 'Cart is empty'],
            'order create: not enough stock' => [['controller' => 'Orders/create.php', 'method' => 'POST', 'setup' => ['UPDATE cart SET quantity = 9']], 409, null],
            'order create: ok' => [['controller' => 'Orders/create.php', 'method' => 'POST'], 201, null],
            'order detail: unknown order' => [['controller' => 'Orders/detail.php', 'route_params' => ['id' => 99]], 404, 'Order not found'],
            'order detail: ok' => [['controller' => 'Orders/detail.php', 'route_params' => ['id' => 3]], 200, null],
            'order list: ok' => [['controller' => 'Orders/list.php'], 200, null],
        ];
    }

    #[DataProvider('domainOutcomes')]
    public function testDomainOutcomesKeepTheirCodesAndMessages(array $scenario, int $status, ?string $message): void
    {
        [$actual, $body, $log] = $this->request($scenario);

        $this->assertSame($status, $actual, json_encode($body) . $log);
        $this->assertStringNotContainsString('API database error', $log);
        if ($message !== null) {
            $this->assertSame(['error' => true, 'message' => $message], $body);
        } elseif ($status >= 400) {
            $this->assertTrue($body['error']);
        } else {
            $this->assertArrayNotHasKey('error', $body);
        }
    }
}
