<?php
/**
 * Index shop knowledge into Qdrant.
 *
 * Usage:
 *   php scripts/ingest_knowledge.php    (exit 1 when indexing failed; the deploy runs it after the stack is up)
 *
 * Requires QDRANT_URL and RAG_ML_URL for semantic embeddings. The default
 * runtime model is bkai-foundation-models/vietnamese-bi-encoder.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

$retriever = new KnowledgeRetriever($pdo, ServiceConfig::fromEnvironment(), dirname(__DIR__));
$documents = $retriever->getDocuments();
$result = $retriever->upsertToQdrant($documents);

echo json_encode([
    'documents' => count($documents),
    'collection' => KnowledgeRetriever::COLLECTION,
    'result' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

// Non-zero exit when nothing was indexed, so the deploy can tell (the chatbot would fall back to lexical search).
exit(($result['success'] ?? false) === true ? 0 : 1);
