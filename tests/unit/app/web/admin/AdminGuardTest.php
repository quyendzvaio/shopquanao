<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * One rule for every admin page: `admin_logged_in` (env admin) OR role admin (DB admin) passes,
 * everyone else goes to the admin login (public/admin/login.php, i.e. 'login.php').
 */
final class AdminGuardTest extends TestCase
{
    public function testGuestIsRedirectedToTheAdminLogin(): void
    {
        $result = AdminGuard::check([]);

        $this->assertNotNull($result);
        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php', $result->payload());
    }

    public function testCustomerRoleIsRedirectedToTheAdminLogin(): void
    {
        $result = AdminGuard::check(['user_id' => 3, 'role' => 'user']);

        $this->assertSame('redirect', $result?->kind());
        $this->assertSame('login.php', $result?->payload());
    }

    public function testEnvAdminFlagIsAllowed(): void
    {
        $this->assertNull(AdminGuard::check(['admin_logged_in' => true]));
    }

    public function testDbAdminRoleIsAllowed(): void
    {
        $this->assertNull(AdminGuard::check(['user_id' => 7, 'role' => 'admin']));
    }

    public function testNullOrEmptyFlagIsTreatedAsMissing(): void
    {
        $this->assertSame('login.php', AdminGuard::check(['admin_logged_in' => null])?->payload());
        $this->assertSame('login.php', AdminGuard::check(['admin_logged_in' => false])?->payload());
    }

    public function testRoleIsComparedStrictly(): void
    {
        $this->assertNotNull(AdminGuard::check(['role' => 'Admin']));
        $this->assertNotNull(AdminGuard::check(['role' => true]));
    }
}
