<?php
/**
 * LLM Factory
 * Tạo provider từ config.
 */
require_once __DIR__ . '/DeepSeekProvider.php';

class LLMFactory {
    /**
     * Tạo provider từ config array:
     * [
     *   'provider' => 'deepseek',
     *   'api_key' => 'sk-...',
     *   'base_url' => 'https://api.deepseek.com',
     *   'model' => 'deepseek-chat',
     * ]
     */
    public static function create(array $config): ?LLMProvider {
        $provider = $config['provider'] ?? '';
        $apiKey = $config['api_key'] ?? '';

        if (!$apiKey) return null;

        return match ($provider) {
            'deepseek' => new DeepSeekProvider(
                apiKey: $apiKey,
                baseUrl: $config['base_url'] ?? 'https://api.deepseek.com',
                model: $config['model'] ?? 'deepseek-chat',
                timeout: (int)($config['timeout'] ?? 60)
            ),
            default => null,
        };
    }

    /**
     * Đọc config từ env vars.
     */
    public static function fromEnv(): ?LLMProvider {
        $getEnv = function(string $key): string {
            $val = getenv($key);
            if ($val !== false && $val !== '') return $val;
            return $_ENV[$key] ?? $_SERVER[$key] ?? '';
        };

        $provider = $getEnv('LLM_PROVIDER');
        if (!$provider) return null;

        return self::create([
            'provider' => $provider,
            'api_key' => $getEnv('LLM_API_KEY'),
            'base_url' => $getEnv('LLM_BASE_URL') ?: 'https://api.deepseek.com',
            'model' => $getEnv('LLM_MODEL') ?: 'deepseek-chat',
            'timeout' => (int)($getEnv('LLM_TIMEOUT') ?: 60),
        ]);
    }
}
