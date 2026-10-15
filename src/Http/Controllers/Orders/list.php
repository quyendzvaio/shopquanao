<?php

$user = authenticate();
$userId = (int)$user['id'];

$statusFilter = $_GET['status'] ?? 'all';

global $pdo;
jsonResponse((new OrderService($pdo))->list($userId, $statusFilter));
