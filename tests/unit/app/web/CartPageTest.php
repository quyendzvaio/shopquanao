<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartPageTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        SqliteShop::product($pdo, 8, 1, 'Quần', 200000, 3, '');
        $pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, 'M'), (2, 5, 8, 1, 'L'), (3, 6, 7, 1, 'S')");
        return $pdo;
    }

    private function page(PDO $pdo): CartPage
    {
        return new CartPage(new CartService($pdo));
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $session = [];

        $result = $this->page($this->shop())->handle(new WebRequest('GET', [], [], []), $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php?error=vui_long_dang_nhap', $result->payload());
    }

    public function testListProvidesTheUsersCartLines(): void
    {
        $session = ['user_id' => 5];

        $result = $this->page($this->shop())->handle(new WebRequest('GET', [], [], []), $session);

        $this->assertTrue($result->isView());
        $items = $result->data()['cart_items'];
        $this->assertCount(2, $items);
        foreach (['cart_id', 'product_id', 'name', 'price', 'image', 'quantity', 'size', 'subtotal'] as $key) {
            $this->assertArrayHasKey($key, $items[0]);
        }
        $this->assertSame('Áo thun', $items[0]['name']);
    }

    public function testRemoveDeletesOnlyTheUsersLineAndRedirectsToTheCart(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5, 'csrf_token' => 'tok'];

        $result = $this->page($pdo)->handle(new WebRequest('POST', [], ['remove' => '1', 'csrf_token' => 'tok'], []), $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('cart.php', $result->payload());
        $this->assertSame(['2', '3'], array_map('strval', $pdo->query('SELECT id FROM cart ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testRemovingAnotherUsersLineChangesNothing(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5, 'csrf_token' => 'tok'];

        $this->page($pdo)->handle(new WebRequest('POST', [], ['remove' => '3', 'csrf_token' => 'tok'], []), $session);

        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testGuestRemoveStillRedirectsToLoginAndDeletesNothing(): void
    {
        $pdo = $this->shop();
        $session = ['csrf_token' => 'tok'];

        $result = $this->page($pdo)->handle(new WebRequest('POST', [], ['remove' => '1', 'csrf_token' => 'tok'], []), $session);

        $this->assertSame('login.php?error=vui_long_dang_nhap', $result->payload());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testRemoveWithAMissingOrWrongTokenRedirectsAndDeletesNothing(): void
    {
        $pdo = $this->shop();

        foreach ([[], ['csrf_token' => 'forged'], ['csrf_token' => '']] as $token) {
            $session = ['user_id' => 5, 'csrf_token' => 'tok'];

            $result = $this->page($pdo)->handle(new WebRequest('POST', [], ['remove' => '1'] + $token, []), $session);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('cart.php', $result->payload());
        }
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testTheOldGetRemoveUrlDeletesNothingEvenWithAValidTokenInTheQuery(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5, 'csrf_token' => 'tok'];

        $result = $this->page($pdo)->handle(new WebRequest('GET', ['remove' => '1', 'csrf_token' => 'tok'], [], []), $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('cart.php', $result->payload());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testRemoveOnAGetWithPostFieldsDeletesNothing(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5, 'csrf_token' => 'tok'];

        $this->page($pdo)->handle(new WebRequest('GET', [], ['remove' => '1', 'csrf_token' => 'tok'], []), $session);

        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testAPostWithoutRemoveStillShowsTheList(): void
    {
        $session = ['user_id' => 5];

        $this->assertTrue($this->page($this->shop())->handle(new WebRequest('POST', [], [], []), $session)->isView());
    }

    public function testTheCartTemplateUpdatesAndRemovesThroughPostFormsWithTheToken(): void
    {
        $session = ['user_id' => 5];
        $data = $this->page($this->shop())->handle(new WebRequest('GET', [], [], []), $session)->data();
        $_SESSION = ['csrf_token' => str_repeat('a', 64)];

        extract($data);
        ob_start();
        include ROOT_DIR . '/templates/pages/cart.php';
        $html = (string) ob_get_clean();
        unset($_SESSION);

        $this->assertStringNotContainsString('update_cart.php?', $html);
        $this->assertStringNotContainsString('cart.php?remove=', $html);
        $this->assertMatchesRegularExpression('/<form id="update-cart-form" action="update_cart.php" method="POST"/', $html);
        $this->assertMatchesRegularExpression('/<form id="remove-cart-form" action="cart.php" method="POST"/', $html);
        $this->assertSame(2, substr_count($html, 'name="csrf_token" value="' . str_repeat('a', 64) . '"'));
    }
}
