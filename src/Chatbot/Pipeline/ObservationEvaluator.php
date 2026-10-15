<?php

class ObservationEvaluator {
    public function evaluate(array $intent, array $plan, array $execution, array $normalized): array {
        $toolStatuses = [];
        $temporaryErrors = [];
        $hardFailures = [];

        foreach (($execution['results'] ?? []) as $id => $entry) {
            $tool = (string)($entry['tool'] ?? '');
            $success = (bool)($entry['success'] ?? false);
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
            $status = $success ? 'success' : 'error';

            if (isset($result['error'])) {
                $status = 'error';
                $temporaryErrors[] = ['id' => (string)$id, 'tool' => $tool, 'error' => (string)$result['error']];
            }
            if (!empty($result['requires_login'])) {
                $status = 'requires_login';
            }

            $toolStatuses[(string)$id] = [
                'tool' => $tool,
                'status' => $status,
                'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            ];
        }

        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        if ($primary === 'product_detail') {
            $requested = (int)($intent['entities']['product_id'] ?? 0);
            $actual = (int)($normalized['cards'][0]['id'] ?? 0);
            if ($requested > 0 && $actual > 0 && $requested !== $actual) {
                $hardFailures[] = 'product_id_mismatch';
            }
        }

        if ($primary === 'order_status') {
            foreach (($normalized['evidence'] ?? []) as $item) {
                if (($item['fact_type'] ?? '') === 'requires_login') {
                    $hardFailures[] = 'requires_login';
                }
            }
        }

        return [
            'tool_statuses' => $toolStatuses,
            'temporary_errors' => $temporaryErrors,
            'hard_failures' => array_values(array_unique($hardFailures)),
            'has_tool_error' => $temporaryErrors !== [],
            'has_hard_failure' => $hardFailures !== [],
        ];
    }
}
