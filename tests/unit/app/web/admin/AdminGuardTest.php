<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminGuardTest extends TestCase
{
    // index.php and manage_orders.php: the admin flag OR the admin role passes, otherwise login.php.

    public function testFlagPageSendsAGuestToLogin(): void
    {
        $result = AdminGuard::check([], true, 'login.php');

        $this->assertNotNull($result);
        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php', $result->payload());
    }

    public function testFlagPageAcceptsTheAdminFlagAlone(): void
    {
        $this->assertNull(AdminGuard::check(['admin_logged_in' => true], true, 'login.php'));
    }

    public function testFlagPageAcceptsTheAdminRoleAlone(): void
    {
        $this->assertNull(AdminGuard::check(['role' => 'admin'], true, 'login.php'));
    }

    public function testFlagPageRejectsACustomerRole(): void
    {
        $result = AdminGuard::check(['user_id' => 3, 'role' => 'user'], true, 'login.php');

        $this->assertSame('login.php', $result?->payload());
    }

    public function testFlagPageTreatsANullFlagAsMissing(): void
    {
        $result = AdminGuard::check(['admin_logged_in' => null], true, 'login.php');

        $this->assertSame('login.php', $result?->payload());
    }

    // add_product.php: role only, login.php.

    public function testRoleOnlyAddProductSendsAGuestToAdminLogin(): void
    {
        $this->assertSame('login.php', AdminGuard::check([], false, 'login.php')?->payload());
    }

    public function testRoleOnlyAddProductIgnoresTheAdminFlag(): void
    {
        $result = AdminGuard::check(['admin_logged_in' => true], false, 'login.php');

        $this->assertSame('login.php', $result?->payload());
    }

    public function testRoleOnlyAcceptsTheAdminRole(): void
    {
        $this->assertNull(AdminGuard::check(['role' => 'admin'], false, 'login.php'));
    }

    // edit_product.php, manage_products.php, manage_users.php: role only, ../login.php (the storefront login).

    public function testRoleOnlyEditPagesSendAGuestToTheStorefrontLogin(): void
    {
        $this->assertSame('../login.php', AdminGuard::check([], false, '../login.php')?->payload());
    }

    public function testRoleOnlyEditPagesRejectACustomerRole(): void
    {
        $result = AdminGuard::check(['role' => 'user'], false, '../login.php');

        $this->assertSame('../login.php', $result?->payload());
    }

    public function testRoleIsComparedStrictly(): void
    {
        $this->assertNotNull(AdminGuard::check(['role' => 'Admin'], false, '../login.php'));
        $this->assertNotNull(AdminGuard::check(['role' => true], false, '../login.php'));
    }
}
