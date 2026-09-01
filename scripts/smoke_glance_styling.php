<?php

if (PHP_SAPI !== 'cli') exit(2);
$root = dirname(__DIR__);
foreach (['GlanceConfig', 'StylingReferenceProvider', 'StyleReference', 'StyleReferenceSet', 'GlanceDemoStylingProvider', 'RawFashionSuggestion', 'RawFashionSuggestionProvider', 'StyleReferenceRawSuggestionAdapter', 'ExtractedFashionItem', 'FashionAttributeExtractor', 'FashionExtractionCache', 'ApplicationFashionExtractionCache', 'FashionPipelineMetrics', 'StructuredLogFashionMetrics', 'FashionExtractionException', 'DeterministicFashionAttributeParser', 'FashionExtractionSemanticValidator', 'LlmFashionAttributeExtractor', 'FashionRequirement', 'FashionRequirementNormalizer', 'ConcurrentProductSearchGateway', 'InternalShopConcurrentProductSearchGateway', 'ParallelComplementaryProductSearcher', 'ComplementaryProductFinder'] as $class) {
    require_once $root . '/api/services/Fashion/' . $class . '.php';
}
require_once $root . '/api/cache/Cache.php';
require_once $root . '/api/controllers/chatbot/llm/LLMFactory.php';
require_once $root . '/api/controllers/chatbot/ProductAttributeNormalizer.php';
require_once $root . '/api/services/Catalog/CatalogTaxonomy.php';
require_once $root . '/api/services/Fashion/ShopComplementaryRequirement.php';
require_once $root . '/api/services/Fashion/FashionTaxonomyNormalizer.php';
require_once $root . '/api/services/Fashion/ComplementaryItemRequirement.php';

$config = GlanceConfig::fromEnvironment();
if (!$config->enabled || $config->mode !== 'demo') {
    fwrite(STDERR, "GLANCE_PROVIDER_MODE=BLOCKED status={$config->status()}\n");
    exit(2);
}
$llm = LLMFactory::fashionExtractionFromEnv();
if ($llm === null) { fwrite(STDERR, "FASHION_EXTRACTION_STATUS=FAIL\n"); exit(2); }
$productId = max(1, (int) (getopt('', ['shop-product-id::'])['shop-product-id'] ?? 50));
$result = (new ComplementaryProductFinder(
    new StyleReferenceRawSuggestionAdapter(new GlanceDemoStylingProvider()),
    new LlmFashionAttributeExtractor($llm),
    new FashionRequirementNormalizer(),
    new ParallelComplementaryProductSearcher(new InternalShopConcurrentProductSearchGateway())
))->find($productId);
$encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach (['glance_product_id', 'glance_inventory_id', 'provider_product_id'] as $forbidden) {
    if (str_contains($encoded, $forbidden)) throw new RuntimeException("Provider identity leaked: {$forbidden}");
}
$searchIds = [];
foreach (($result['groups'] ?? []) as $group) foreach (($group['products'] ?? []) as $product) if (is_array($product)) $searchIds[(int) ($product['id'] ?? 0)] = true;
$displayed = array_map(static fn (array $product): int => (int) $product['id'], $result['products'] ?? []);
if (array_diff($displayed, array_keys($searchIds)) !== []) throw new RuntimeException('Displayed product was not returned by private Product Search');
echo json_encode([
    'status' => $result['status'], 'provider_mode' => $result['provider_mode'] ?? null,
    'references' => count($result['raw_suggestions'] ?? []), 'private_product_ids' => $displayed,
    'grounded' => true, 'GLANCE_PROVIDER_MODE' => 'demo', 'GLANCE_LIVE_CONNECTIVITY' => 'BLOCKED',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
