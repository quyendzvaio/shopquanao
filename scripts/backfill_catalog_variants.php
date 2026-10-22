<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$report = (new CatalogVariantBackfill($pdo))->run();
fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
