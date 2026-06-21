<?php
/**
 * API Login — xác thực qua database (password_verify).
 * Không hardcode credentials.
 */
require_once __DIR__ . '/../../config.php';

$data = getJsonInput();
$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    errorResponse('Missing email or password', 400);
}

global $pdo;

// Find user by email or username
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
$stmt->execute([$email, $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    errorResponse('Invalid email or password', 401);
}

if ((int)$user['status'] === 0) {
    errorResponse('Account is locked. Contact admin.', 403);
}

// Generate & store token
$token = bin2hex(random_bytes(32));
$stmt = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
$stmt->execute([$token, $user['id']]);

jsonResponse([
    'message' => 'Login successful',
    'user' => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'role'     => $user['role'],
    ],
    'token' => $token,
]);
