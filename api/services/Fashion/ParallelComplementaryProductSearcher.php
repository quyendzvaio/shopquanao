<?php

final class ParallelComplementaryProductSearcher
{
    public function __construct(
        private ConcurrentProductSearchGateway $gateway,
        private int $maxConcurrency = 4,
        private FashionPipelineMetrics $metrics = new StructuredLogFashionMetrics()
    ) {
        $this->maxConcurrency = max(1, min(8, $this->maxConcurrency));
    }

    /**
     * @param list<FashionRequirement> $requirements
     * @return list<array{requirement: array, products: array, search_failed: bool, error: ?string, duration_ms: int}>
     */
    public function search(array $requirements): array
    {
        $byKey = [];
        $entries = [];
        $attempts = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof FashionRequirement) {
                throw new InvalidArgumentException('Invalid shop complementary requirement');
            }
            $key = $requirement->key();
            $entries[] = ['key' => $key, 'requirement' => $requirement];
            if (isset($byKey[$key])) {
                // Providers may repeat the same item across multiple outfit sets.
                // Preserve each output group, but reuse the identical private
                // Product Search result instead of creating another HTTP call.
                continue;
            }
            $byKey[$key] = $requirement;
            $attempts[$key] = $requirement->searchAttempts();
        }

        $resolved = [];
        $durations = array_fill_keys(array_keys($byKey), 0);
        $queries = array_fill_keys(array_keys($byKey), []);
        $pending = array_fill_keys(array_keys($byKey), true);
        $level = 0;
        while ($pending !== []) {
            $searches = [];
            foreach (array_keys($pending) as $key) {
                if (isset($attempts[$key][$level])) $searches[$key] = $attempts[$key][$level];
            }
            if ($searches === []) break;
            $rawResults = $this->gateway->searchBatch($searches, $this->maxConcurrency);
            foreach ($searches as $key => $query) {
                $queries[$key][] = $query;
                $raw = $rawResults[$key] ?? [
                    'success' => false, 'products' => [], 'error' => 'Product Search result missing', 'duration_ms' => 0,
                ];
                $durations[$key] += (int) ($raw['duration_ms'] ?? 0);
                $hasProducts = is_array($raw['products'] ?? null) && $raw['products'] !== [];
                $failed = !($raw['success'] ?? false);
                $hasNext = isset($attempts[$key][$level + 1]);
                if (!$failed && !$hasProducts && $hasNext) {
                    $this->metrics->increment('fashion_search_relaxation_total');
                    continue;
                }
                $resolved[$key] = ['raw' => $raw, 'level' => $level];
                unset($pending[$key]);
            }
            $level++;
        }

        $groups = [];
        foreach ($entries as $entry) {
            $key = $entry['key'];
            $requirement = $entry['requirement'];
            $resolution = $resolved[$key] ?? null;
            $raw = $resolution['raw'] ?? [
                'success' => false, 'products' => [], 'error' => 'Product Search result missing', 'duration_ms' => 0,
            ];
            $products = [];
            foreach (($raw['products'] ?? []) as $product) {
                if (!is_array($product) || (int) ($product['id'] ?? 0) <= 0) {
                    continue;
                }
                // Provider identifiers are never part of the frontend product shape.
                unset($product['provider_product_id'], $product['provider_variant_id'], $product['provider_color_id']);
                $products[] = $product;
            }
            $groups[] = [
                'requirement' => [
                    'priority' => $requirement->sourcePriority,
                    'category' => $requirement->sourceCategory,
                    'shop_search' => $requirement->search,
                    'styles' => $requirement->styles,
                    'colors' => $requirement->colors,
                    'materials' => $requirement->materials,
                    'patterns' => $requirement->patterns,
                    'fits' => $requirement->fits,
                ],
                'products' => $products,
                'search_failed' => !($raw['success'] ?? false),
                'error' => isset($raw['error']) ? (string) $raw['error'] : null,
                'duration_ms' => $durations[$key],
                'relaxation_level' => (int) ($resolution['level'] ?? 0),
                'search_attempts' => count($queries[$key]),
                'search_queries' => $queries[$key],
            ];
        }
        return $groups;
    }
}
