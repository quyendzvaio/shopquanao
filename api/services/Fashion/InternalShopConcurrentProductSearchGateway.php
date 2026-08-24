<?php

/** Concurrent adapter to the existing private Product Search application boundary. */
final class InternalShopConcurrentProductSearchGateway implements ConcurrentProductSearchGateway
{
    private string $url;
    private string $serviceToken;
    private int $timeoutMs;

    public function __construct(?string $url = null, ?string $serviceToken = null, ?int $timeoutMs = null)
    {
        $this->url = $url ?? (string) (getenv('SHOP_INTERNAL_URL') ?: 'http://127.0.0.1/api/internal/mcp');
        $this->serviceToken = $serviceToken ?? (string) (getenv('MCP_SERVICE_TOKEN') ?: '');
        $this->timeoutMs = max(250, $timeoutMs ?? (int) (getenv('MCP_REQUEST_TIMEOUT_MS') ?: 30000));
        if ($this->serviceToken === '') {
            throw new RuntimeException('MCP_SERVICE_TOKEN is required for parallel Product Search');
        }
    }

    public function searchBatch(array $searches, int $maxConcurrency): array
    {
        $maxConcurrency = max(1, min(8, $maxConcurrency));
        $results = [];
        foreach (array_chunk($searches, $maxConcurrency, true) as $chunk) {
            $results += $this->executeChunk($chunk);
        }
        return $results;
    }

    private function executeChunk(array $searches): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $started = [];
        try {
            foreach ($searches as $key => $arguments) {
                $handle = curl_init($this->url);
                if ($handle === false) {
                    throw new RuntimeException('Unable to initialize Product Search request');
                }
                $payload = json_encode([
                    'operation' => 'tool.call',
                    'tool' => 'search_products',
                    'arguments' => $arguments,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-MCP-Service-Token: ' . $this->serviceToken,
                    ],
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_CONNECTTIMEOUT_MS => min(2000, $this->timeoutMs),
                    CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                ]);
                $handles[$key] = $handle;
                $started[$key] = microtime(true);
                curl_multi_add_handle($multi, $handle);
            }

            do {
                $status = curl_multi_exec($multi, $active);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Parallel Product Search transport failed: ' . curl_multi_strerror($status));
                }
                if ($active > 0) {
                    curl_multi_select($multi, 0.25);
                }
            } while ($active > 0);

            $results = [];
            foreach ($handles as $key => $handle) {
                $duration = (int) ((microtime(true) - $started[$key]) * 1000);
                $body = curl_multi_getcontent($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($handle);
                $decoded = is_string($body) ? json_decode($body, true) : null;
                if ($curlError !== '' || $status < 200 || $status >= 300 || !is_array($decoded)) {
                    $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
                    $results[$key] = [
                        'success' => false,
                        'products' => [],
                        'error' => $message !== '' ? $message : ($curlError !== '' ? $curlError : "Product Search HTTP $status"),
                        'duration_ms' => $duration,
                    ];
                    continue;
                }
                $products = $decoded['result']['products'] ?? null;
                if (!is_array($products)) {
                    $results[$key] = [
                        'success' => false,
                        'products' => [],
                        'error' => 'Product Search returned an invalid response',
                        'duration_ms' => $duration,
                    ];
                    continue;
                }
                $results[$key] = [
                    'success' => true,
                    'products' => array_values(array_filter($products, fn ($product) => is_array($product) && (int) ($product['id'] ?? 0) > 0)),
                    'error' => null,
                    'duration_ms' => $duration,
                ];
            }
            return $results;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }
}
