<?php
require_once __DIR__ . '/../../config.php';

$category = $_GET['category'] ?? '';
$search   = trim($_GET['search'] ?? '');

global $pdo;

$sql = "SELECT * FROM faqs WHERE 1=1";
$params = [];

$allowedCats = ['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size'];
if ($category && in_array($category, $allowedCats)) {
    $sql .= " AND category = ?";
    $params[] = $category;
}

if ($search) {
    $sql .= " AND (question LIKE ? OR answer LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY priority, id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faqs = $stmt->fetchAll();

foreach ($faqs as &$f) {
    $f['id'] = (int)$f['id'];
    $f['priority'] = (int)$f['priority'];
}

jsonResponse(['faqs' => $faqs]);
