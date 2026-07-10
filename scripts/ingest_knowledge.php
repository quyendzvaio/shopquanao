<?php
/**
 * Index shop knowledge into Qdrant.
 *
 * Usage:
 *   php scripts/ingest_knowledge.php
 *
 * Requires QDRANT_URL. Uses local deterministic embeddings by default, so no
 * external embedding API is required for development.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/cache/Cache.php';
require_once __DIR__ . '/../api/controllers/chatbot/KnowledgeRetriever.php';

$retriever = new KnowledgeRetriever($pdo, dirname(__DIR__));
$documents = $retriever->getDocuments();
$result = $retriever->upsertToQdrant($documents);

echo json_encode([
    'documents' => count($documents),
    'collection' => KnowledgeRetriever::COLLECTION,
    'result' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
