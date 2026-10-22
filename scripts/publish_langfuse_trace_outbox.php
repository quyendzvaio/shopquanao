<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$once = in_array('--once', $argv, true);
$publisher = new LangfuseOtlpTracePublisher($pdo);
do {
    $report = $publisher->runBatch();
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if (!$once) {
        sleep($report['disabled'] ? 10 : 1);
    }
} while (!$once);
