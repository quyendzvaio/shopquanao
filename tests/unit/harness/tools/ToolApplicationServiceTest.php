<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ToolApplicationServiceTest extends TestCase
{
    /** The free-shipping tool honours the policy the service was built with, not the environment. */
    public function testFreeshipToolUsesInjectedPolicy(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'congso,phongvan,mualanh');
        SqliteShop::product($pdo, 58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'streetstyle,dichoi');
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (7, 53, 1, 'M')");
        $tools = new ToolApplicationService($pdo, 7, new ServiceConfig(), new FreeShippingPolicy(750000));

        $result = $tools->execute('suggest_freeship_items', []);

        $this->assertSame(750000, $result['threshold']);
        $this->assertSame(415000, $result['subtotal']);
        $this->assertSame(335000, $result['gap']);
    }
}
