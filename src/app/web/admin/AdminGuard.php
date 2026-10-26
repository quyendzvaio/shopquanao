<?php

/**
 * The admin pages' single access check: the env admin (`admin_logged_in`) or a database admin (role `admin`)
 * may enter; everyone else, guests and customers alike, is sent to the admin login (public/admin/login.php).
 */
final class AdminGuard
{
    /**
     * @param array<string,mixed> $session
     * @return PageResult|null a redirect to the admin login, or null when the visitor may see the page
     */
    public static function check(array $session): ?PageResult
    {
        $allowed = !empty($session['admin_logged_in']) || ($session['role'] ?? null) === 'admin';

        return $allowed ? null : PageResult::redirect('login.php');
    }
}
