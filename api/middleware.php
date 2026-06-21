<?php
require_once __DIR__ . '/config.php';

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
    $stmt = $pdo->prepare("SELECT id, username, email, role, status FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

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
