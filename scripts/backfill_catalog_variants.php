<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/controllers/chatbot/ProductAttributeNormalizer.php';
require_once __DIR__ . '/../api/services/Catalog/CatalogVariantBackfill.php';

$report = (new CatalogVariantBackfill($pdo))->run();
fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
