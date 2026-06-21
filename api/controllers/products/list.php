<?php
/**
 * Products list API — tối ưu FULLTEXT + LIKE hybrid + composite indexes.
 * GET /api/products?search=&category=&min_price=&max_price=&sort=&in_stock=
 *
 * FULLTEXT index ignores words < innodb_ft_min_token_size (default 3).
 * Vietnamese words like "áo" (2 char) use LIKE fallback.
 */
require_once __DIR__ . '/../../config.php';

global $pdo;

$search   = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$minPrice = $_GET['min_price'] ?? '';
$maxPrice = $_GET['max_price'] ?? '';
$inStock  = $_GET['in_stock'] ?? '';
$sort     = $_GET['sort'] ?? 'newest';
$limit    = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : null;
$page     = $limit ? max(1, (int)($_GET['page'] ?? 1)) : 1;
$offset   = $limit ? ($page - 1) * $limit : 0;

$where  = [];
$params = [];

// ---- Hybrid search: FULLTEXT (fast) + LIKE (fallback for short words) ----
if ($search !== '') {
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $search);
    $words = preg_split('/\s+/u', $clean);
    $words = array_filter($words, fn($w) => mb_strlen($w) >= 2);

    // FULLTEXT words (≥ 3 chars, indexed)
    $ftsWords = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3));

    if (!empty($ftsWords)) {
        // Use FULLTEXT in BOOLEAN MODE for the long words
        $ftsQuery = '+' . implode(' +', array_map(function($w) {
            return str_replace(['\\', "'"], ['\\\\', "\\'"], $w) . '*';
        }, $ftsWords));
        $where[] = "MATCH(p.name) AGAINST(? IN BOOLEAN MODE)";
        $params[] = $ftsQuery;
    }

    // Always use LIKE for the full search string (catches short words too)
    // This ensures "áo" or "áo khoác" still finds results
    $where[] = "p.name LIKE ?";
    $params[] = "%$search%";
}

if ($category !== '') {
    $where[] = "p.category_id = ?";
    $params[] = (int)$category;
}
if ($minPrice !== '') {
    $where[] = "p.price >= ?";
    $params[] = (float)$minPrice;
}
if ($maxPrice !== '') {
    $where[] = "p.price <= ?";
    $params[] = (float)$maxPrice;
}
if ($inStock === '1') {
    $where[] = "p.stock > 0";
}

$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Count
$sqlCount = "SELECT COUNT(*) FROM products p" . $whereClause;
$stmt = $pdo->prepare($sqlCount);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Sort — uses composite idx_category_price when category + price filters present
switch ($sort) {
    case 'price_asc':  $orderBy = ' ORDER BY p.price ASC'; break;
    case 'price_desc': $orderBy = ' ORDER BY p.price DESC'; break;
    case 'name_asc':   $orderBy = ' ORDER BY p.name ASC'; break;
    default:           $orderBy = ' ORDER BY p.id DESC'; break;
}

// Data query — SELECT only needed columns (omit description for list perf)
$sqlData = "SELECT p.id, p.category_id, p.name, p.price, p.stock, p.image, c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id"
            . $whereClause . $orderBy;

if ($limit !== null) {
    $sqlData .= " LIMIT $limit OFFSET $offset";
}

$stmt = $pdo->prepare($sqlData);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cast types
foreach ($products as &$p) {
    $p['id']    = (int)$p['id'];
    $p['price'] = (float)$p['price'];
    $p['stock'] = (int)$p['stock'];
    $p['category_id'] = $p['category_id'] ? (int)$p['category_id'] : null;
}
unset($p);

jsonResponse([
    'products' => $products,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'total'      => $total,
        'total_pages'=> $limit ? (int)ceil($total / $limit) : 1,
    ],
]);
