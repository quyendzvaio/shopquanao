<?php

$user = authenticate();
jsonResponse([
    'user' => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'role'     => $user['role'],
    ]
]);
