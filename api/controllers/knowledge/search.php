<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../cache/Cache.php';
require_once __DIR__ . '/../chatbot/KnowledgeRetriever.php';

$query = trim($_GET['q'] ?? $_GET['query'] ?? '');
$category = trim($_GET['category'] ?? '');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

if ($query === '') {
    errorResponse('Query is required', 400);
}

global $pdo;
$retriever = new KnowledgeRetriever($pdo);
$result = $retriever->search($query, $category !== '' ? $category : null, $limit);

jsonResponse($result);
