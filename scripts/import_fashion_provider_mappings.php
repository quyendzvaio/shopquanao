<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/services/Fashion/AnchorProductRef.php';
require_once __DIR__ . '/../api/services/Fashion/FashionProviderProductMapping.php';
require_once __DIR__ . '/../api/services/Fashion/FashionProviderMappingRepository.php';
require_once __DIR__ . '/../api/services/Fashion/OfflineFashionMappingImporter.php';

$options = getopt('', ['file:', 'provider::', 'sync-version:', 'dry-run']);
$file = isset($options['file']) ? (string) $options['file'] : '';
$provider = isset($options['provider']) ? (string) $options['provider'] : 'findmine';
$syncVersion = isset($options['sync-version']) ? (string) $options['sync-version'] : '';
if ($file === '' || $syncVersion === '') {
    fwrite(STDERR, "Usage: php scripts/import_fashion_provider_mappings.php --file=/path/mappings.csv --sync-version=VERSION [--provider=findmine] [--dry-run]\n");
    exit(2);
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Mapping file is not readable: $file\n");
    exit(2);
}

$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open mapping file.\n");
    exit(2);
}
$headers = fgetcsv($handle, 0, ',', '"', '\\');
$required = ['shop_product_id', 'provider_product_id'];
if (!is_array($headers) || array_diff($required, $headers) !== []) {
    fclose($handle);
    fwrite(STDERR, "CSV headers must include: " . implode(', ', $required) . "\n");
    exit(2);
}

$rows = (function () use ($handle, $headers): Generator {
    try {
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($headers), '');
            yield array_combine($headers, array_slice($values, 0, count($headers)));
        }
    } finally {
        fclose($handle);
    }
})();

$dryRun = array_key_exists('dry-run', $options);
$report = (new OfflineFashionMappingImporter(
    $pdo,
    new FashionProviderMappingRepository($pdo)
))->import($rows, $provider, $syncVersion, $dryRun);

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
exit($report['failed'] === 0 ? 0 : 1);
