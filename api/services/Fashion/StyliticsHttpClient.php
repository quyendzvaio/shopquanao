<?php

/**
 * cURL client for the Stylitics Complete the Look REST API.
 * Stylitics has no public MCP; this is the direct HTTP transport.
 */
final class StyliticsHttpClient implements StyliticsHttpClientContract
{
    private const TIMEOUT_MS_ENV = 'STYLITICS_TIMEOUT_MS';
    private const RETRY_ATTEMPTS_ENV = 'STYLITICS_RETRY_ATTEMPTS';

    public function __construct(private StyliticsConfig $config) {}

    public function completeTheLook(string $anchorSku, ?string $anchorVariantSku = null): array
    {
        if (!$this->config->enabled || $this->config->mode !== 'live') {
            throw new StyliticsApiException('PROVIDER_DISABLED', 'Stylitics live provider is not enabled');
        }
        if ($this->config->apiUrl === '' || $this->config->apiKey === '') {
            throw new StyliticsApiException('PROVIDER_MISCONFIGURED', 'Stylitics API URL and key are required');
        }
        if (trim($anchorSku) === '') {
            throw new StyliticsApiException('INVALID_ANCHOR', 'Anchor SKU is required for Complete the Look');
        }

        $attempts = $this->retryAttempts();
        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->request($anchorSku, $anchorVariantSku);
            } catch (StyliticsApiException $error) {
                $lastError = $error;
                if (!$error->retryable || $attempt === $attempts) throw $error;
            }
        }
        throw $lastError ?? new StyliticsApiException('PROVIDER_UNAVAILABLE', 'Stylitics request failed');
    }

    /** @return array<string,mixed> */
    private function request(string $anchorSku, ?string $anchorVariantSku): array
    {
        $url = rtrim($this->config->apiUrl, '/');
        $body = [
            'item_number' => $anchorSku,
            'client_item_number' => $anchorVariantSku ?? $anchorSku,
        ];
        $started = microtime(true);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new StyliticsApiException('PROVIDER_UNAVAILABLE', 'Unable to initialize Stylitics HTTP client', true);
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs(),
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs()),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->config->apiKey,
            ],
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if ($raw === false || $raw === '') {
            error_log(json_encode(['provider' => 'stylitics', 'operation' => 'complete_the_look', 'success' => false, 'failure_category' => 'NETWORK_ERROR', 'http_status' => $status, 'elapsed_ms' => $elapsedMs]));
            throw new StyliticsApiException('NETWORK_ERROR', 'Stylitics request failed: ' . $curlError, true);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            error_log(json_encode(['provider' => 'stylitics', 'operation' => 'complete_the_look', 'success' => false, 'failure_category' => 'MALFORMED_RESPONSE', 'http_status' => $status, 'elapsed_ms' => $elapsedMs]));
            throw new StyliticsApiException('MALFORMED_RESPONSE', 'Stylitics returned a non-JSON body');
        }
        if ($status < 200 || $status >= 300) {
            $category = $status === 401 || $status === 403 ? 'AUTH_ERROR' : ($status === 429 ? 'RATE_LIMITED' : ($status >= 500 ? 'PROVIDER_UNAVAILABLE' : 'BAD_REQUEST'));
            error_log(json_encode(['provider' => 'stylitics', 'operation' => 'complete_the_look', 'success' => false, 'failure_category' => $category, 'http_status' => $status, 'elapsed_ms' => $elapsedMs]));
            // Never echo provider error bodies; they may contain tenant details.
            throw new StyliticsApiException($category, "Stylitics HTTP {$status}", $status >= 500 || $status === 429);
        }
        error_log(json_encode(['provider' => 'stylitics', 'operation' => 'complete_the_look', 'success' => true, 'http_status' => $status, 'elapsed_ms' => $elapsedMs]));
        return $decoded;
    }

    private function timeoutMs(): int
    {
        $value = getenv(self::TIMEOUT_MS_ENV);
        return $value === false || $value === '' ? 8000 : max(1000, min(30000, (int) $value));
    }

    private function retryAttempts(): int
    {
        $value = getenv(self::RETRY_ATTEMPTS_ENV);
        return $value === false || $value === '' ? 2 : max(1, min(3, (int) $value));
    }
}
