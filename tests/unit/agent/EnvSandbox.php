<?php

declare(strict_types=1);

/** Test helper: sets/unsets env keys across getenv, $_ENV and $_SERVER, and restores them. */
trait EnvSandbox
{
    private const ENV_KEYS = [
        'APP_ENV',
        'MCP_SERVICE_TOKEN',
        'FREE_SHIPPING_THRESHOLD', 'FREESHIP_NUDGE_MAX_GAP',
        'QDRANT_URL', 'RAG_ML_URL', 'KNOWLEDGE_COLLECTION', 'EMBEDDING_PROVIDER', 'EMBEDDING_MODEL',
        'KNOWLEDGE_HYBRID_CANDIDATES', 'KNOWLEDGE_VERSION', 'KNOWLEDGE_RERANKER_MODEL', 'RAG_RERANK_TIMEOUT',
        'RAG_EMBED_TIMEOUT', 'RERANKER_URL', 'CHATBOT_ALLOWED_ORIGINS', 'CHATBOT_MAX_MESSAGE_CHARS',
        'ADMIN_EMAIL', 'ADMIN_USER', 'ADMIN_PASSWORD', 'CHAT_GRAPH_URL', 'CHAT_GRAPH_TOKEN', 'CHAT_GRAPH_TIMEOUT',
        'PROMPT_CACHE_TTL',
    ];
    /** @var array<string,array{0:string|false,1:mixed,2:mixed}> */
    private array $envSnapshot = [];

    private function cleanEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = [
                getenv($key),
                $_ENV[$key] ?? null,
                $_SERVER[$key] ?? null,
            ];
            $this->setEnv($key, null);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }

    private function restoreEnv(): void
    {
        foreach ($this->envSnapshot as $key => [$env, $envVar, $server]) {
            $env === false ? putenv($key) : putenv("$key=$env");
            $envVar === null ? $this->unsetGlobal($_ENV, $key) : $_ENV[$key] = $envVar;
            $server === null ? $this->unsetGlobal($_SERVER, $key) : $_SERVER[$key] = $server;
        }
        $this->envSnapshot = [];
    }

    private function unsetGlobal(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }
}
