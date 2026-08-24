<?php

if (PHP_SAPI !== 'cli') exit(2);
$root = dirname(__DIR__);
require_once $root . '/api/cache/Cache.php';
require_once $root . '/api/controllers/chatbot/llm/LLMFactory.php';
foreach (['FindMineConfig', 'FindMineMcpClientContract', 'FindMineProviderException', 'FindMineMcpClient', 'RawFashionSuggestion', 'RawFashionSuggestionProvider', 'FindMineDemoFashionProvider', 'ExtractedFashionItem', 'FashionAttributeExtractor', 'FashionExtractionCache', 'ApplicationFashionExtractionCache', 'FashionPipelineMetrics', 'StructuredLogFashionMetrics', 'FashionExtractionException', 'DeterministicFashionAttributeParser', 'FashionExtractionSemanticValidator', 'LlmFashionAttributeExtractor', 'FashionRequirement', 'FashionRequirementNormalizer', 'ConcurrentProductSearchGateway', 'InternalShopConcurrentProductSearchGateway', 'ParallelComplementaryProductSearcher', 'ComplementaryProductFinder'] as $class) {
    require_once $root . '/api/services/Fashion/' . $class . '.php';
}
require_once $root . '/api/services/Catalog/CatalogTaxonomy.php';
require_once $root . '/api/services/Fashion/ShopComplementaryRequirement.php';
require_once $root . '/api/services/Fashion/FashionTaxonomyNormalizer.php';
require_once $root . '/api/services/Fashion/ComplementaryItemRequirement.php';

$options = getopt('', ['shop-product-id::']);
$productId = max(1, (int) ($options['shop-product-id'] ?? 50));
$config = FindMineConfig::fromEnvironment();
if ($config->status() !== 'DEMO_READY') {
    fwrite(STDERR, "FINDMINE_DEMO_MCP_STATUS=FAIL config_status={$config->status()}\n");
    exit(2);
}
$llm = LLMFactory::fashionExtractionFromEnv();
if ($llm === null) {
    fwrite(STDERR, "FASHION_EXTRACTION_STATUS=FAIL missing LLM configuration\n");
    exit(2);
}

$client = new FindMineMcpClient($config);
$started = microtime(true);
$initialize = $client->initialize();
$tools = $client->listTools();
$tool = null;
foreach ($tools as $candidate) if (($candidate['name'] ?? '') === 'get_complete_the_look') $tool = $candidate;
if ($tool === null || !isset($tool['inputSchema']['properties']['fake_result'])) {
    fwrite(STDERR, "FINDMINE_DEMO_MCP_STATUS=FAIL get_complete_the_look.fake_result missing\n");
    exit(1);
}
$rawProbe = $client->call('get_complete_the_look', [
    'product_id' => 'shopquanao-demo-anchor-' . $productId,
    'in_stock' => true,
    'on_sale' => false,
    'return_pdp_item' => false,
    'fake_result' => true,
]);
$rawProbeSummary = ['success' => true, 'content_count' => count($rawProbe['content'] ?? [])];
foreach (($rawProbe['content'] ?? []) as $content) {
    if (($content['type'] ?? '') !== 'text' || !is_string($content['text'] ?? null)) continue;
    $decodedProbe = json_decode($content['text'], true);
    if (!is_array($decodedProbe)) continue;
    $looks = is_array($decodedProbe['looks'] ?? null) ? $decodedProbe['looks'] : [];
    $rawProbeSummary['response_uuid'] = $decodedProbe['response_uuid'] ?? null;
    $rawProbeSummary['look_count'] = count($looks);
    $rawProbeSummary['look_product_counts'] = array_map(static fn(mixed $look): int => is_array($look) && is_array($look['products'] ?? null) ? count($look['products']) : 0, $looks);
    $rawProbeSummary['json_sha256'] = hash('sha256', $content['text']);
    break;
}

$result = (new ComplementaryProductFinder(
    new FindMineDemoFashionProvider($client),
    new LlmFashionAttributeExtractor($llm),
    new FashionRequirementNormalizer(),
    new ParallelComplementaryProductSearcher(new InternalShopConcurrentProductSearchGateway())
))->find($productId);
$encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach (['provider_product_id', 'provider_variant_id', 'provider_color_id', 'provider_item_id'] as $forbidden) {
    if (str_contains($encoded, $forbidden)) throw new RuntimeException("Provider identity leaked: $forbidden");
}
$searchResultIds = [];
foreach (($result['groups'] ?? []) as $group) {
    foreach (($group['products'] ?? []) as $product) {
        if (is_array($product) && (int) ($product['id'] ?? 0) > 0) $searchResultIds[(int) $product['id']] = true;
    }
}
$displayedIds = array_values(array_map(static fn (array $product): int => (int) $product['id'], $result['products']));
if (array_diff($displayedIds, array_keys($searchResultIds)) !== []) {
    throw new RuntimeException('Displayed product was not returned by Product Search');
}
$safe = [
    'artifact_sha' => '28a15b86ac0a7b212336748005393f88bcbfdad1',
    'protocol_version' => $initialize['protocolVersion'] ?? null,
    'tool' => 'get_complete_the_look',
    'provider_mode' => 'findmine_demo',
    'node_env' => $config->childEnvironment()['NODE_ENV'],
    'status' => $result['status'],
    'provider_error' => $result['provider_error'],
    'raw_probe_summary' => $rawProbeSummary,
    'raw_suggestions' => $result['raw_suggestions'],
    'extracted_items' => $result['extracted_items'],
    'normalized_requirements' => $result['normalized_requirements'],
    'search_groups' => $result['groups'],
    'product_search_result_count' => count($searchResultIds),
    'displayed_product_ids' => $displayedIds,
    'displayed_ids_grounded' => true,
    'timings' => $result['timings'] + ['smoke_total_ms' => (int) round((microtime(true) - $started) * 1000)],
];
fwrite(STDOUT, json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result['status'] === 'success' ? 0 : 1);
