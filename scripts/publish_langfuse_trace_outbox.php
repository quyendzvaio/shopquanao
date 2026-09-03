<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/services/Observability/LangfuseTraceOutbox.php';
require_once __DIR__ . '/../api/services/Observability/LangfuseOtlpTracePublisher.php';

$once = in_array('--once', $argv, true);
$publisher = new LangfuseOtlpTracePublisher($pdo);
do {
    $report = $publisher->runBatch();
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if (!$once) {
        sleep($report['disabled'] ? 10 : 1);
    }
} while (!$once);
