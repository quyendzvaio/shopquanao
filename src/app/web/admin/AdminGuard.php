<?php

/**
 * The admin pages' access check. Each page keeps its own condition and its own redirect target (they are
 * not consistent, and that is preserved): index and manage_orders accept the admin flag as well as the role,
 * every other page checks the role only.
 */
final class AdminGuard
{
    /**
     * @param array<string,mixed> $session
     * @param bool $acceptAdminFlag true: `admin_logged_in` OR role admin passes; false: only role admin passes
     * @return PageResult|null a redirect to $loginUrl, or null when the visitor may see the page
     */
    public static function check(array $session, bool $acceptAdminFlag, string $loginUrl): ?PageResult
    {
        $isAdminRole = isset($session['role']) && $session['role'] === 'admin';
        $allowed = $acceptAdminFlag ? (isset($session['admin_logged_in']) || $isAdminRole) : $isAdminRole;

        return $allowed ? null : PageResult::redirect($loginUrl);
    }
}
