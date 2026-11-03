<?php
require_once dirname(__DIR__, 2) . '/core/http/helpers.php';

/**
 * Authenticate user via Bearer token.
 * Returns user array or sends 401 and exits.
 */
function authenticate() {
    $token = getBearerToken();
    if (!$token) {
        errorResponse('Unauthorized - missing token', 401);
    }

    global $pdo;
    $user = (new UserService($pdo))->userForApiToken($token);

    if (!$user) {
        errorResponse('Unauthorized - invalid token', 401);
    }

    if ((int)$user['status'] === 0) {
        errorResponse('Account is locked', 403);
    }

    return $user;
}

/**
 * Authenticate and require admin role.
 */
function requireAdmin() {
    $user = authenticate();
    if ($user['role'] !== 'admin') {
        errorResponse('Forbidden - admin only', 403);
    }
    return $user;
}

/**
 * Generate a random API token.
 */
function generateToken() {
    return bin2hex(random_bytes(32));
}
