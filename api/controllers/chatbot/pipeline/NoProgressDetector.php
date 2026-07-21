<?php

class NoProgressDetector {
    private array $seen = [];

    public function observe(array $execution): array {
        $fingerprints = [];
        $noProgress = false;

        foreach (($execution['results'] ?? []) as $id => $entry) {
            $tool = (string)($entry['tool'] ?? '');
            if ($tool === '') continue;
            $fingerprint = $this->fingerprint($tool, is_array($entry['args'] ?? null) ? $entry['args'] : [], is_array($entry['result'] ?? null) ? $entry['result'] : []);
            $fingerprints[] = $fingerprint;
            if (isset($this->seen[$fingerprint['fingerprint']])) {
                $noProgress = true;
            }
            $this->seen[$fingerprint['fingerprint']] = true;
        }

        return [
            'no_progress' => $noProgress,
            'fingerprints' => $fingerprints,
        ];
    }

    public function wouldRepeatTool(array $call, array $previousExecution): bool {
        $tool = (string)($call['tool'] ?? '');
        $args = is_array($call['args'] ?? null) ? $call['args'] : [];
        $argsHash = $this->stableHash($args);
        foreach (($previousExecution['results'] ?? []) as $entry) {
            if ((string)($entry['tool'] ?? '') !== $tool) continue;
            $prevArgs = is_array($entry['args'] ?? null) ? $entry['args'] : [];
            if ($this->stableHash($prevArgs) === $argsHash) {
                return true;
            }
        }
        return false;
    }

    private function fingerprint(string $tool, array $args, array $result): array {
        $signature = $this->resultSignature($tool, $result);
        $argsHash = $this->stableHash($args);
        return [
            'tool' => $tool,
            'args_hash' => $argsHash,
            'result_signature' => $signature,
            'fingerprint' => sha1($tool . '|' . $argsHash . '|' . $signature),
        ];
    }

    private function resultSignature(string $tool, array $result): string {
        if (isset($result['error'])) {
            return 'error:' . (string)$result['error'];
        }
        if ($tool === 'search_products') {
            $ids = array_map(fn($p) => (int)($p['id'] ?? 0), array_slice(is_array($result['products'] ?? null) ? $result['products'] : [], 0, 10));
            return 'products:' . implode(',', $ids);
        }
        if ($tool === 'get_product_detail') {
            return 'product:' . (int)($result['product']['id'] ?? 0);
        }
        if ($tool === 'retrieve_knowledge') {
            $parts = [];
            foreach (array_slice(is_array($result['results'] ?? null) ? $result['results'] : [], 0, 5) as $item) {
                $parts[] = (string)($item['source'] ?? '') . '#' . (string)($item['title'] ?? '') . '#' . (string)($item['category'] ?? '');
            }
            return 'knowledge:' . implode('|', $parts);
        }
        if ($tool === 'suggest_size') {
            return 'size:' . (string)($result['recommended']['size_name'] ?? '');
        }
        if ($tool === 'get_order_status') {
            if (!empty($result['requires_login'])) return 'requires_login';
            $orders = [];
            foreach (array_slice(is_array($result['orders'] ?? null) ? $result['orders'] : [], 0, 5) as $order) {
                $orders[] = (int)($order['id'] ?? 0) . ':' . (string)($order['status'] ?? '');
            }
            return 'orders:' . implode('|', $orders);
        }
        return $this->stableHash($result);
    }

    private function stableHash(array $value): string {
        $normalized = $this->normalize($value);
        return sha1(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function normalize($value) {
        if (!is_array($value)) return $value;
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }
        return $value;
    }
}
