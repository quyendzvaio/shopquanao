<?php
/**
 * Index shop knowledge into Qdrant.
 *
 * Usage:
 *   php scripts/ingest_knowledge.php
 *
 * Requires QDRANT_URL and RAG_ML_URL for semantic embeddings. The default
 * runtime model is bkai-foundation-models/vietnamese-bi-encoder.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

$retriever = new KnowledgeRetriever($pdo, dirname(__DIR__));
$documents = $retriever->getDocuments();
$result = $retriever->upsertToQdrant($documents);

echo json_encode([
    'documents' => count($documents),
    'collection' => KnowledgeRetriever::COLLECTION,
    'result' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
