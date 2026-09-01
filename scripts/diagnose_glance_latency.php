<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root = dirname(__DIR__);
foreach (['GlanceConfig', 'GlanceMcpException', 'GlanceMcpClientContract', 'GlanceMcpClient'] as $class) {
    require_once $root . '/api/services/Fashion/' . $class . '.php';
}

$config = GlanceConfig::fromEnvironment();
if ($config->status() !== 'LIVE_READY') {
    fwrite(STDERR, "GLANCE_LATENCY_PROBE=BLOCKED status={$config->status()}\n");
    exit(2);
}

$client = new GlanceMcpClient($config);
$startedAt = hrtime(true);

/** @return int elapsed milliseconds */
function elapsedMs(int $startedAt): int
{
    return (int) round((hrtime(true) - $startedAt) / 1_000_000);
}

try {
    $searchStartedAt = hrtime(true);
    $search = $client->call('search_fashion_products', [
        'gender' => 'MALE',
        'country' => 'IN',
        'currency' => 'INR',
        'query' => 'white smart casual shirt',
        'context_summary' => 'Find a compatible generic fashion reference.',
        'context_image_ref' => '',
        'occasion' => 'smart-casual',
    ]);
    $products = $search['structuredContent']['tiers'][0]['products'] ?? [];
    $providerSku = '';
    foreach (is_array($products) ? $products : [] as $product) {
        if (is_array($product) && !empty($product['in_stock']) && trim((string) ($product['sku'] ?? '')) !== '') {
            $providerSku = trim((string) $product['sku']);
            break;
        }
    }
    $searchMs = elapsedMs($searchStartedAt);
    if ($providerSku === '') {
        throw new RuntimeException('Glance search returned no usable in-stock provider anchor');
    }

    $mixStartedAt = hrtime(true);
    $mix = $client->call($config->toolName, [
        'anchor_sku' => $providerSku,
        'query' => '',
        'context_image_ref' => '',
        'gender' => 'MALE',
        'occasion' => 'smart-casual',
    ]);
    $mixMs = elapsedMs($mixStartedAt);

    echo json_encode([
        'status' => 'PASS',
        'search_ms' => $searchMs,
        'search_product_count' => is_array($products) ? count($products) : 0,
        'mix_ms' => $mixMs,
        'mix_content_blocks' => is_array($mix['content'] ?? null) ? count($mix['content']) : 0,
        'mix_has_structured_content' => is_array($mix['structuredContent'] ?? null),
        'total_ms' => elapsedMs($startedAt),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $category = $error instanceof GlanceMcpException ? $error->category : 'PROBE_FAILURE';
    fwrite(STDERR, json_encode([
        'status' => 'FAIL',
        'category' => $category,
        'message' => $error->getMessage(),
        'total_ms' => elapsedMs($startedAt),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
