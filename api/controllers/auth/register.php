<?php
require_once __DIR__ . '/../../config.php';

$data = getJsonInput();

$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$username = trim($data['username'] ?? $data['fullname'] ?? '');

if (!$email || !$password || !$username) {
    errorResponse('Missing required fields: username, email, password', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    errorResponse('Invalid email format', 400);
}

if (strlen($password) < 6) {
    errorResponse('Password must be at least 6 characters', 400);
}

global $pdo;

// Check email uniqueness
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    errorResponse('Email already registered', 409);
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$token  = bin2hex(random_bytes(32));

$stmt = $pdo->prepare("INSERT INTO users (username, email, password, api_token, role, status) VALUES (?, ?, ?, ?, 'user', 1)");
$stmt->execute([$username, $email, $hashed, $token]);

$userId = (int)$pdo->lastInsertId();

jsonResponse([
    'message' => 'Registration successful',
    'user' => [
        'id'       => $userId,
        'username' => $username,
        'email'    => $email,
        'role'     => 'user',
    ],
    'token' => $token,
], 201);
