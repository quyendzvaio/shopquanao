<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AddToCartActionTest extends TestCase
{
    private function shop(int $stock = 3): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, $stock, '');
        return $pdo;
    }

    private function visit(PDO $pdo, string $method, array $post, array $session): PageResult
    {
        return (new AddToCartAction(new CartService($pdo)))->handle(new WebRequest($method, [], $post, []), $session);
    }

    private function lines(PDO $pdo): array
    {
        return $pdo->query('SELECT product_id, quantity, size FROM cart ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function soldOutScript(int|string $id): string
    {
        return "<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id={$id}';</script>";
    }

    public function testGuestIsSentToLoginEvenOnGet(): void
    {
        $pdo = $this->shop();

        foreach (['POST', 'GET'] as $method) {
            $result = $this->visit($pdo, $method, ['product_id' => '7'], []);

            $this->assertSame('output', $result->kind());
            $this->assertSame(
                "<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>",
                $result->payload()
            );
        }
        $this->assertSame([], $this->lines($pdo));
    }

    public function testNonPostReturnsAnEmptyView(): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, 'GET', ['product_id' => '7'], ['user_id' => 5]);

        $this->assertTrue($result->isView());
        $this->assertSame([], $result->data());
        $this->assertSame([], $this->lines($pdo));
    }

    public function testAddRedirectsToTheCartAndStoresQuantityAndSize(): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '2', 'selected_size' => 'm'], ['user_id' => 5]);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('cart.php', $result->payload());
        $this->assertSame([['product_id' => 7, 'quantity' => 2, 'size' => 'M']], $this->lines($pdo));
    }

    public function testBuyNowGoesStraightToCheckout(): void
    {
        $result = $this->visit($this->shop(), 'POST', ['product_id' => '7', 'action' => 'buy_now'], ['user_id' => 5]);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('checkout.php', $result->payload());
    }

    public function testFreesizeIsStoredUppercasedAndTheCartPageStillSelectsIt(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, 'POST', ['product_id' => '7', 'selected_size' => 'Freesize'], ['user_id' => 5]);

        $this->assertSame('FREESIZE', $pdo->query('SELECT size FROM cart')->fetchColumn());

        $session = ['user_id' => 5];
        $data = (new CartPage(new CartService($pdo)))->handle(new WebRequest('GET', [], [], []), $session)->data();
        extract($data);
        ob_start();
        include ROOT_DIR . '/templates/pages/cart.php';
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/<option value="Freesize"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="S"\s+selected>/', $html);
    }

    public function testMissingSizeDefaultsToSAsTheHomePageFormPostsNone(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, 'POST', ['product_id' => '7'], ['user_id' => 5]);

        $this->assertSame([['product_id' => 7, 'quantity' => 1, 'size' => 'S']], $this->lines($pdo));
    }

    public function testSecondAddMergesQuantityAndKeepsOneLine(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '1'], ['user_id' => 5]);
        $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '2', 'selected_size' => 'L'], ['user_id' => 5]);

        $this->assertSame([['product_id' => 7, 'quantity' => 3, 'size' => 'L']], $this->lines($pdo));
    }

    public function testOutOfStockProductGivesTheSoldOutScript(): void
    {
        $pdo = $this->shop(0);

        $result = $this->visit($pdo, 'POST', ['product_id' => '7'], ['user_id' => 5]);

        $this->assertSame('output', $result->kind());
        $this->assertSame($this->soldOutScript(7), $result->payload());
        $this->assertSame([], $this->lines($pdo));
    }

    public function testMergedQuantityAboveStockGivesTheSameScriptAndKeepsTheOldLine(): void
    {
        $pdo = $this->shop(3);
        $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '2'], ['user_id' => 5]);

        $result = $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '2'], ['user_id' => 5]);

        $this->assertSame($this->soldOutScript(7), $result->payload());
        $this->assertSame([['product_id' => 7, 'quantity' => 2, 'size' => 'S']], $this->lines($pdo));
    }

    public function testUnknownProductAndBadQuantityGiveTheSoldOutScriptWithTheCastId(): void
    {
        $pdo = $this->shop();

        $this->assertSame($this->soldOutScript(99), $this->visit($pdo, 'POST', ['product_id' => '99'], ['user_id' => 5])->payload());
        $this->assertSame($this->soldOutScript(7), $this->visit($pdo, 'POST', ['product_id' => '7', 'quantity' => '0'], ['user_id' => 5])->payload());
        $this->assertSame($this->soldOutScript(0), $this->visit($pdo, 'POST', [], ['user_id' => 5])->payload());
        $this->assertSame([], $this->lines($pdo));
    }
}
