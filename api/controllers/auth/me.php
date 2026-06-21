<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
jsonResponse([
    'user' => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'role'     => $user['role'],
    ]
]);
