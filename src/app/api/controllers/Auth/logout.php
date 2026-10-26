<?php

$user = authenticate();

// Generate token on login — already stored. Logout: nullify.
global $pdo;
(new UserService($pdo))->logout((int)$user['id']);

jsonResponse(['message' => 'Logged out successfully']);
