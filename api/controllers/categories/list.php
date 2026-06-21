<?php
require_once __DIR__ . '/../../config.php';

global $pdo;
$stmt = $pdo->query("SELECT * FROM categories ORDER BY id");
$categories = $stmt->fetchAll();

jsonResponse(['categories' => $categories]);
