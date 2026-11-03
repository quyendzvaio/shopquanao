<?php

$category = $_GET['category'] ?? '';
$search   = trim($_GET['search'] ?? '');

global $pdo;

$faqs = (new FaqService($pdo))->search(is_string($category) ? $category : null, $search);

foreach ($faqs as &$f) {
    $f['id'] = (int)$f['id'];
    $f['priority'] = (int)$f['priority'];
}

jsonResponse(['faqs' => $faqs]);
