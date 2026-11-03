<?php

$user = authenticate();
$userId = (int)$user['id'];

$statusFilter = $_GET['status'] ?? 'all';

global $pdo;
try {
    jsonResponse((new OrderService($pdo))->list($userId, $statusFilter));
} catch (PDOException $error) {
    databaseErrorResponse($error);
}
