<?php

/**
 * The admin pages' single access check: the env admin (`admin_logged_in`) or a database admin (role `admin`)
 * may enter; everyone else, guests and customers alike, is sent to the admin login (public/admin/login.php).
 */
final class AdminGuard
{
    /**
     * An admin session with no real user row: the env admin (user_id 0), who cannot shop. A database admin has a user_id above 0.
     *
     * @param array<string,mixed> $session
     */
    public static function isEnvAdmin(array $session): bool
    {
        $admin = !empty($session['admin_logged_in']) || ($session['role'] ?? null) === 'admin';

        return $admin && (int) ($session['user_id'] ?? 0) <= 0;
    }

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
