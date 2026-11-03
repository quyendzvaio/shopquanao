<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpdateCartActionTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        $pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, 'M'), (2, 6, 7, 1, 'S')");
        return $pdo;
    }

    /** Posts the cart-line fields with the session's CSRF token by default; $token = null sends none. */
    private function visit(PDO $pdo, array $fields, array $session, string $method = 'POST', ?string $token = 'tok'): PageResult
    {
        $session['csrf_token'] = 'tok';
        if ($token !== null) {
            $fields['csrf_token'] = $token;
        }

        return (new UpdateCartAction(new CartService($pdo)))->handle(new WebRequest($method, [], $fields, []), $session);
    }

    private function line(PDO $pdo, int $id): array
    {
        return $pdo->query("SELECT quantity, size FROM cart WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    public function testQtyUpdatesTheQuantityWithoutAStockCheckAndRedirects(): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, ['id' => '1', 'value' => '99', 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame('cart.php', $result->payload());
        $this->assertSame('redirect', $result->kind());
        $this->assertSame('99', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 1')->fetchColumn());
    }

    /** @return array<string,array{0:string|int}> */
    public static function badQuantities(): array
    {
        return ['negative' => ['-5'], 'zero' => ['0'], 'text' => ['abc'], 'decimal' => ['2.5'], 'padded' => [' 3'], 'signed' => ['+3'], 'huge' => ['99999999999999999999']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQuantities')]
    public function testANonPositiveOrNonIntegerQuantityIsANoOpThatStillRedirects(string $value): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, ['id' => '1', 'value' => $value, 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('cart.php', $result->payload());
        $this->assertSame(['quantity' => 2, 'size' => 'M'], $this->line($pdo, 1));
    }

    public function testAPositiveIntegerQuantityIsWritten(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, ['id' => '1', 'value' => '3', 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame(['quantity' => 3, 'size' => 'M'], $this->line($pdo, 1));
    }

    public function testAnyOtherTypeUpdatesTheSize(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, ['id' => '1', 'value' => 'XL', 'type' => 'size'], ['user_id' => 5]);

        $this->assertSame('XL', $pdo->query('SELECT size FROM cart WHERE id = 1')->fetchColumn());
    }

    public function testAnotherUsersLineIsNotTouched(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, ['id' => '2', 'value' => '9', 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame('1', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 2')->fetchColumn());
    }

    public function testMissingParametersOrGuestStillRedirectWithoutWriting(): void
    {
        $pdo = $this->shop();

        foreach ([
            [['id' => '1', 'type' => 'qty'], ['user_id' => 5]],
            [['id' => '1', 'value' => '0', 'type' => 'qty'], ['user_id' => 5]],
            [['id' => '1', 'value' => '9', 'type' => 'qty'], []],
        ] as [$fields, $session]) {
            $this->assertSame('cart.php', $this->visit($pdo, $fields, $session)->payload());
        }
        $this->assertSame('2', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 1')->fetchColumn());
    }

    public function testMissingOrWrongTokenRedirectsWithoutWriting(): void
    {
        $pdo = $this->shop();

        foreach ([null, 'forged', ''] as $token) {
            foreach ([['qty', '99'], ['size', 'XL']] as [$type, $value]) {
                $result = $this->visit($pdo, ['id' => '1', 'value' => $value, 'type' => $type], ['user_id' => 5], 'POST', $token);

                $this->assertSame('redirect', $result->kind());
                $this->assertSame('cart.php', $result->payload());
            }
        }
        $this->assertSame(['quantity' => 2, 'size' => 'M'], $this->line($pdo, 1));
    }

    public function testGetWritesNothingEvenWithAValidTokenAndTheOldQueryUrl(): void
    {
        $pdo = $this->shop();
        $request = new WebRequest('GET', ['id' => '1', 'value' => '99', 'type' => 'qty', 'csrf_token' => 'tok'], [], []);

        $session = ['user_id' => 5, 'csrf_token' => 'tok'];
        $result = (new UpdateCartAction(new CartService($pdo)))->handle($request, $session);
        $viaPostFields = $this->visit($pdo, ['id' => '1', 'value' => '99', 'type' => 'qty'], ['user_id' => 5], 'GET');

        $this->assertSame('cart.php', $result->payload());
        $this->assertSame('cart.php', $viaPostFields->payload());
        $this->assertSame(['quantity' => 2, 'size' => 'M'], $this->line($pdo, 1));
    }

    public function testASessionWithoutATokenNeverWrites(): void
    {
        $pdo = $this->shop();
        $request = new WebRequest('POST', [], ['id' => '1', 'value' => '99', 'type' => 'qty', 'csrf_token' => ''], []);
        $session = ['user_id' => 5];

        (new UpdateCartAction(new CartService($pdo)))->handle($request, $session);

        $this->assertSame(['quantity' => 2, 'size' => 'M'], $this->line($pdo, 1));
    }
}
