<?php
require_once __DIR__ . '/../../config.php';

$data = getJsonInput();

$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    errorResponse('Missing email or password', 400);
}

global $pdo;

// Check admin hardcoded credentials
if (($email === 'admin@gmail.com' || $email === 'admin') && $password === '123456') {
    $token = bin2hex(random_bytes(32));
    // admin might not exist in DB — create on the fly or return static
    jsonResponse([
        'message' => 'Login successful',
        'user' => [
            'id'       => 0,
            'username' => 'Admin Shop',
            'email'    => 'admin@gmail.com',
            'role'     => 'admin',
        ],
        'token' => 'admin-static-token',
    ]);
}

// Normal user login
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
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
