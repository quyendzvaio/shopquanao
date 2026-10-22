<?php

class ParallelToolExecutor {
    private ChatbotToolGateway $toolGateway;

    public function __construct(ChatbotToolGateway $toolGateway) {
        $this->toolGateway = $toolGateway;
    }

    public function execute(array $plan): array {
        $results = [];
        $spans = [];
        $totalStart = microtime(true);

        foreach (($plan['batches'] ?? []) as $batchIndex => $batch) {
            foreach ($batch as $call) {
                $tool = (string)($call['tool'] ?? '');
                $id = (string)($call['id'] ?? $tool);
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                if ($tool === '') continue;

                $start = microtime(true);
                try {
                    $result = $this->toolGateway->execute($tool, $args);
                    $success = true;
                } catch (Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                    $success = false;
                }

                $duration = (int)((microtime(true) - $start) * 1000);
                $results[$id] = [
                    'tool' => $tool,
                    'args' => $args,
                    'result' => $result,
                    'success' => $success,
                    'duration_ms' => $duration,
                ];
                $spans[$tool . '_' . $id . '_ms'] = $duration;
                if ($tool === 'search_products') $spans['product_tool_ms'] = ($spans['product_tool_ms'] ?? 0) + $duration;
                if ($tool === 'retrieve_knowledge') {
                    $spans['retrieval_ms'] = ($spans['retrieval_ms'] ?? 0) + $duration;
                    foreach (($result['latency'] ?? []) as $latencyKey => $latencyValue) {
                        if (is_numeric($latencyValue)) {
                            $spans[$latencyKey] = ($spans[$latencyKey] ?? 0) + (int)$latencyValue;
                        }
                    }
                    foreach (($result['cache_metrics'] ?? []) as $cacheKey => $cacheValue) {
                        $spans[$cacheKey] = (bool)$cacheValue;
                    }
                }
                if ($tool === 'get_order_status') $spans['order_tool_ms'] = ($spans['order_tool_ms'] ?? 0) + $duration;
            }
            $spans['batch_' . $batchIndex . '_completed'] = true;
        }

        $spans['tool_execution_ms'] = (int)((microtime(true) - $totalStart) * 1000);
        return ['results' => $results, 'spans' => $spans];
    }
}
