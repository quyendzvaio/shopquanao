<?php

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;
try {
    jsonResponse((new CartService($pdo))->list($userId));
} catch (PDOException $error) {
    databaseErrorResponse($error);
}
