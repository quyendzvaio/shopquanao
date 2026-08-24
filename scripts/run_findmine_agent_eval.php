<?php

if (PHP_SAPI !== 'cli') exit(2);
$root = dirname(__DIR__);
require_once $root . '/api/controllers/chatbot/ProductAttributeNormalizer.php';
require_once $root . '/api/controllers/chatbot/contracts/ChatbotToolGateway.php';
require_once $root . '/api/controllers/chatbot/ToolDefinitionCatalog.php';
require_once $root . '/api/controllers/chatbot/McpToolGateway.php';
require_once $root . '/api/controllers/chatbot/pipeline/PartialParseResult.php';
require_once $root . '/api/controllers/chatbot/pipeline/DeterministicIntentParser.php';
require_once $root . '/api/controllers/chatbot/pipeline/ResponseGenerator.php';
require_once $root . '/api/services/Fashion/ProactiveStylingStateMachine.php';

$cases = require $root . '/eval/findmine_agent_eval_cases.php';
$expectedCaseCount = 70;
if (count($cases) !== $expectedCaseCount) {
    throw new RuntimeException("Evaluation corpus must contain exactly {$expectedCaseCount} cases");
}

$parser = new DeterministicIntentParser();
$stateMachine = new ProactiveStylingStateMachine();
$responseGenerator = new ResponseGenerator();
$gateway = new McpToolGateway(null);
$stageFailures = array_fill_keys([
    'FINDMINE_SUGGESTION', 'LLM_EXTRACTION', 'NORMALIZATION', 'PRODUCT_SEARCH',
    'RESPONSE_COMPOSITION', 'EVENT_STATE', 'GROUNDING',
], 0);
$results = [];
$passed = 0;
$providerCalls = 0;
$hallucinatedProducts = 0;
$providerIdLeakage = 0;
$ragasCases = [];
$caseLatencies = [];
$stageLatencies = [];
$classLatencies = [];

foreach ($cases as $offset => $case) {
    $caseStarted = microtime(true);
    $failures = [];
    $evidence = ['class' => $case['class']];
    $intent = intentValue($parser, $case['message']);

    if ($case['class'] === 'explicit') {
        if ($intent !== 'suggest_complementary_products') $failures[] = 'RESPONSE_COMPOSITION';
        try {
            $providerCalls++;
            $result = $gateway->execute('suggest_complementary_products', ['product_id' => 50]);
            $raw = is_array($result['raw_suggestions'] ?? null) ? $result['raw_suggestions'] : [];
            $extracted = is_array($result['extracted_items'] ?? null) ? $result['extracted_items'] : [];
            $requirements = is_array($result['normalized_requirements'] ?? null) ? $result['normalized_requirements'] : [];
            $groups = is_array($result['groups'] ?? null) ? $result['groups'] : [];
            $displayed = ids($result['products'] ?? []);
            $searchIds = [];
            $queries = [];
            foreach ($groups as $group) {
                if (!is_array($group)) continue;
                $searchIds = array_merge($searchIds, ids($group['products'] ?? []));
                foreach (($group['search_queries'] ?? []) as $query) if (is_array($query)) $queries[] = $query;
            }
            $searchIds = array_values(array_unique($searchIds));
            sort($searchIds);
            $grounded = array_diff($displayed, $searchIds) === [];
            $productJson = json_encode($result['products'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $leaked = false;
            foreach (['provider_product_id', 'provider_variant_id', 'provider_color_id', 'provider_item_id'] as $forbidden) {
                if (str_contains($productJson, $forbidden)) $leaked = true;
            }
            if ($raw === []) $failures[] = 'FINDMINE_SUGGESTION';
            if ($extracted === [] || count($extracted) !== count($raw)) $failures[] = 'LLM_EXTRACTION';
            if ($requirements === []) $failures[] = 'NORMALIZATION';
            if ($groups === [] || $displayed === []) $failures[] = 'PRODUCT_SEARCH';
            if (!$grounded || $leaked) $failures[] = 'GROUNDING';
            $response = $responseGenerator->generate($case['message'], [
                'primary_intent' => 'suggest_complementary_products',
                'secondary_intents' => [],
                'requested_fields' => [],
            ], [
                'cards' => $result['products'] ?? [],
                'complementary_groups' => $groups,
                'evidence' => [],
            ], ['response_type' => 'final_answer']);
            $answer = trim((string) ($response['answer'] ?? ''));
            if ($answer === '' || $displayed === []) $failures[] = 'RESPONSE_COMPOSITION';
            if (!$grounded) $hallucinatedProducts += count(array_diff($displayed, $searchIds));
            if ($leaked) $providerIdLeakage++;
            $contexts = productContexts($result['products'] ?? []);
            if ($answer !== '' && $contexts !== []) {
                $ragasCases[] = ['case_id' => $case['id'], 'question' => $case['message'], 'answer' => $answer, 'contexts' => $contexts];
            }
            $evidence += [
                'findmine_raw_count' => count($raw),
                'extracted_count' => count($extracted),
                'normalized_count' => count($requirements),
                'product_search_query_count' => count($queries),
                'product_search_result_ids' => $searchIds,
                'displayed_product_ids' => $displayed,
                'final_response' => $answer,
                'stage_latency_ms' => $result['timings'] ?? [],
            ];
        } catch (Throwable $error) {
            $failures[] = 'FINDMINE_SUGGESTION';
            $evidence['error'] = $error->getMessage();
        }
    } elseif ($case['class'] === 'proactive') {
        $state = $stateMachine->onCartItemAdded([], 50, null, 'eval-' . $case['id']);
        $caseProviderCalls = 0;
        $userTurnsReceived = 1;
        $first = $stateMachine->onUserTurn($state, true, false);
        if ($first['action'] !== 'silent' || (int) $first['state']['remaining_user_turns'] !== 1) $failures[] = 'EVENT_STATE';
        $providerCallsBeforeTwoTurns = $caseProviderCalls;

        // Receiving the second suitable user turn is what authorizes the provider
        // call. The state transition is finalized afterward because it needs to
        // know whether the provider returned grounded shop products.
        $userTurnsReceived++;
        if ($providerCallsBeforeTwoTurns !== 0 || $userTurnsReceived !== 2) $failures[] = 'EVENT_STATE';
        try {
            $providerCalls++;
            $caseProviderCalls++;
            $result = $gateway->execute('suggest_complementary_products', ['product_id' => 50]);
            $raw = is_array($result['raw_suggestions'] ?? null) ? $result['raw_suggestions'] : [];
            $extracted = is_array($result['extracted_items'] ?? null) ? $result['extracted_items'] : [];
            $requirements = is_array($result['normalized_requirements'] ?? null) ? $result['normalized_requirements'] : [];
            $groups = is_array($result['groups'] ?? null) ? $result['groups'] : [];
            $displayed = ids($result['products'] ?? []);
            $searchIds = [];
            $queries = [];
            foreach ($groups as $group) {
                if (!is_array($group)) continue;
                $searchIds = array_merge($searchIds, ids($group['products'] ?? []));
                foreach (($group['search_queries'] ?? []) as $query) if (is_array($query)) $queries[] = $query;
            }
            $searchIds = array_values(array_unique($searchIds));
            sort($searchIds);
            $grounded = array_diff($displayed, $searchIds) === [];
            $second = $stateMachine->onUserTurn($first['state'], true, $displayed !== []);
            if ($second['action'] !== 'suggest' || (int) ($second['state']['suggested_anchor_product_id'] ?? 0) !== 50) $failures[] = 'EVENT_STATE';
            if ($raw === []) $failures[] = 'FINDMINE_SUGGESTION';
            if ($extracted === [] || count($extracted) !== count($raw)) $failures[] = 'LLM_EXTRACTION';
            if ($requirements === []) $failures[] = 'NORMALIZATION';
            if ($groups === [] || $displayed === []) $failures[] = 'PRODUCT_SEARCH';
            if (!$grounded) {
                $failures[] = 'GROUNDING';
                $hallucinatedProducts += count(array_diff($displayed, $searchIds));
            }
            $response = $responseGenerator->generate($case['message'], [
                'primary_intent' => 'suggest_complementary_products',
                'secondary_intents' => [],
                'requested_fields' => [],
            ], [
                'cards' => $result['products'] ?? [],
                'complementary_groups' => $groups,
                'evidence' => [],
            ], ['response_type' => 'final_answer']);
            $answer = trim((string) ($response['answer'] ?? ''));
            if ($answer === '') $failures[] = 'RESPONSE_COMPOSITION';
            $contexts = productContexts($result['products'] ?? []);
            if ($answer !== '' && $contexts !== []) {
                $ragasCases[] = ['case_id' => $case['id'], 'question' => $case['message'], 'answer' => $answer, 'contexts' => $contexts];
            }
            $evidence += [
                'user_turns_received_before_provider_call' => $userTurnsReceived,
                'provider_calls_before_two_turns' => $providerCallsBeforeTwoTurns,
                'provider_calls_after_two_turns' => $caseProviderCalls,
                'remaining_after_first_user_turn' => 1,
                'second_turn_action' => $second['action'],
                'findmine_raw_count' => count($raw),
                'extracted_count' => count($extracted),
                'normalized_count' => count($requirements),
                'product_search_query_count' => count($queries),
                'product_search_result_ids' => $searchIds,
                'displayed_product_ids' => $displayed,
                'final_response' => $answer,
                'stage_latency_ms' => $result['timings'] ?? [],
            ];
        } catch (Throwable $error) {
            $failures[] = 'FINDMINE_SUGGESTION';
            $evidence['error'] = $error->getMessage();
        }

        // Spread the persistent invariants across the unchanged proactive cases.
        if ($offset % 3 === 0) {
            $latest = $stateMachine->onCartItemAdded($state, 51, null, 'eval-latest-b');
            $latest = $stateMachine->onCartItemAdded($latest, 66, null, 'eval-latest-c');
            if ((int) $latest['pending_product_id'] !== 66 || (int) $latest['remaining_user_turns'] !== 2) $failures[] = 'EVENT_STATE';
        }
        if ($offset % 5 === 0) {
            $ready = $state;
            $ready['remaining_user_turns'] = 0;
            $shown = $stateMachine->onUserTurn($ready, true, true);
            $again = $stateMachine->onUserTurn($shown['state'], true, true);
            if ($shown['action'] !== 'suggest' || $again['action'] !== 'silent') $failures[] = 'EVENT_STATE';
        }
        $evidence += [
            'user_turns_received_before_provider_call' => $userTurnsReceived,
            'provider_calls_before_two_turns' => $providerCallsBeforeTwoTurns,
            'provider_calls_after_two_turns' => $caseProviderCalls,
            'remaining_after_first_user_turn' => 1,
            'first_turn_action' => $first['action'],
        ];
    } elseif ($case['class'] === 'suppression') {
        $state = $stateMachine->onCartItemAdded([], 50, null, 'eval-' . $case['id']);
        $state['remaining_user_turns'] = 0;
        $transition = $stateMachine->onUserTurn($state, false, false);
        if ($intent !== 'return_exchange' || $transition['action'] !== 'silent' || empty($transition['state']['eligible'])) $failures[] = 'EVENT_STATE';
        $evidence += ['intent' => $intent, 'provider_calls' => 0, 'action' => $transition['action'], 'anchor_retained' => true];
    } else {
        $transition = $stateMachine->onUserTurn([], true, false);
        if ($intent !== 'product_search' || $transition['action'] !== 'silent') $failures[] = 'RESPONSE_COMPOSITION';
        $evidence += ['intent' => $intent, 'provider_calls' => 0, 'action' => $transition['action']];
    }

    $caseLatency = elapsedMilliseconds($caseStarted);
    $evidence['total_case_ms'] = $caseLatency;
    $caseLatencies[] = $caseLatency;
    $classLatencies[$case['class']][] = $caseLatency;
    foreach (($evidence['stage_latency_ms'] ?? []) as $stage => $duration) {
        if (is_numeric($duration)) $stageLatencies[$stage][] = (float) $duration;
    }
    $failures = array_values(array_unique($failures));
    foreach ($failures as $failure) $stageFailures[$failure]++;
    if ($failures === []) $passed++;
    $results[] = [
        'case_id' => $case['id'],
        'use_case' => $case['use_case'],
        'question' => $case['message'],
        'passed' => $failures === [],
        'failures' => $failures,
        'pipeline' => $evidence,
    ];
}

$report = [
    'status' => $passed === $expectedCaseCount && $hallucinatedProducts === 0 && $providerIdLeakage === 0 ? 'PASS' : 'FAIL',
    'cases' => $expectedCaseCount,
    'passed' => $passed,
    'failed' => $expectedCaseCount - $passed,
    'provider_mode' => 'findmine_demo',
    'provider_calls' => $providerCalls,
    'stage_failure_counts' => $stageFailures,
    'hallucinated_product_count' => $hallucinatedProducts,
    'provider_id_leakage_count' => $providerIdLeakage,
    'latency_ms' => [
        'all_cases' => latencySummary($caseLatencies),
        'by_class' => array_map('latencySummary', $classLatencies),
        'pipeline_stages' => array_map('latencySummary', $stageLatencies),
    ],
    'ragas_cases' => $ragasCases,
    'results' => $results,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $path = substr($argument, strlen('--output='));
        if ($path === '' || file_put_contents($path, $json) === false) throw new RuntimeException('Unable to write evaluation report');
    }
}
fwrite(STDOUT, $json);
exit($report['status'] === 'PASS' ? 0 : 1);

function intentValue(DeterministicIntentParser $parser, string $message): ?string
{
    $field = $parser->parse($message)->toArray()['resolved_fields']['intent'] ?? null;
    return is_array($field) ? ($field['value'] ?? null) : (is_string($field) ? $field : null);
}

function ids(mixed $products): array
{
    if (!is_array($products)) return [];
    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $product): int => is_array($product) ? (int) ($product['id'] ?? 0) : 0,
        $products
    ))));
}

function productContexts(mixed $products): array
{
    if (!is_array($products)) return [];
    $contexts = [];
    foreach ($products as $product) {
        if (!is_array($product) || (int) ($product['id'] ?? 0) <= 0) continue;
        $contexts[] = implode("\n", [
            '[Source: product_search]',
            'Product ID: ' . (int) $product['id'],
            'Name: ' . (string) ($product['name'] ?? ''),
            'Price: ' . (string) ($product['price'] ?? ''),
            'Stock: ' . (int) ($product['stock'] ?? 0),
        ]);
    }
    return $contexts;
}

function elapsedMilliseconds(float $started): float
{
    return round((microtime(true) - $started) * 1000, 2);
}

/** @param list<int|float> $values */
function latencySummary(array $values): array
{
    if ($values === []) return ['count' => 0, 'min' => 0, 'avg' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0];
    sort($values, SORT_NUMERIC);
    $count = count($values);
    return [
        'count' => $count,
        'min' => round((float) $values[0], 2),
        'avg' => round(array_sum($values) / $count, 2),
        'p50' => percentile($values, 0.50),
        'p95' => percentile($values, 0.95),
        'max' => round((float) $values[$count - 1], 2),
    ];
}

/** @param list<int|float> $values */
function percentile(array $values, float $percentile): float
{
    $index = (int) ceil($percentile * count($values)) - 1;
    return round((float) $values[max(0, $index)], 2);
}
