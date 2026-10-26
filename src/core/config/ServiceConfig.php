<?php

/**
 * Environment-derived settings of the domain services (knowledge retrieval,
 * product rerank, free shipping), read once. Each key keeps the lookup its
 * service used before: getenv only, except the free-shipping keys, which fall
 * back getenv -> $_ENV -> $_SERVER because PHP-FPM may run with clear_env.
 */
final readonly class ServiceConfig
{
    /**
     * @param ?string $rerankerUrl base URL of the product reranker sidecar (`/rerank` is appended); null disables it
     */
    public function __construct(
        public ?string $qdrantUrl = null,
        public ?string $ragMlUrl = null,
        public string $knowledgeCollection = 'shop_knowledge_v2',
        public string $embeddingProvider = 'rag_ml',
        public string $embeddingModel = 'bkai-foundation-models/vietnamese-bi-encoder',
        public int $knowledgeHybridCandidates = 12,
        public string $knowledgeVersion = 'v1',
        public string $knowledgeRerankerModel = 'itdainb/PhoRanker',
        public int $ragRerankTimeout = 6,
        public int $ragEmbedTimeout = 8,
        public ?string $rerankerUrl = null,
        public int $freeShippingThreshold = 500000,
        public int $freeshipNudgeMaxGap = 200000,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $candidates = self::env('KNOWLEDGE_HYBRID_CANDIDATES');

        return new self(
            qdrantUrl: self::url('QDRANT_URL'),
            ragMlUrl: self::url('RAG_ML_URL'),
            knowledgeCollection: self::env('KNOWLEDGE_COLLECTION') ?? 'shop_knowledge_v2',
            embeddingProvider: self::env('EMBEDDING_PROVIDER') ?? 'rag_ml',
            embeddingModel: self::env('EMBEDDING_MODEL') ?? 'bkai-foundation-models/vietnamese-bi-encoder',
            knowledgeHybridCandidates: $candidates === null ? 12 : (int) $candidates,
            knowledgeVersion: getenv('KNOWLEDGE_VERSION') ?: 'v1',
            knowledgeRerankerModel: getenv('KNOWLEDGE_RERANKER_MODEL') ?: 'itdainb/PhoRanker',
            ragRerankTimeout: self::timeout('RAG_RERANK_TIMEOUT', 6),
            ragEmbedTimeout: self::timeout('RAG_EMBED_TIMEOUT', 8),
            rerankerUrl: self::env('RERANKER_URL') ?? 'http://reranker:8000',
            freeShippingThreshold: max(0, self::intEnvWithFallback('FREE_SHIPPING_THRESHOLD', 500000)),
            freeshipNudgeMaxGap: max(0, self::intEnvWithFallback('FREESHIP_NUDGE_MAX_GAP', 200000)),
        );
    }

    /** getenv only; unset or empty is null. */
    private static function env(string $key): ?string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : null;
    }

    private static function url(string $key): ?string
    {
        $value = self::env($key);
        return $value === null ? null : rtrim($value, '/');
    }

    private static function timeout(string $key, int $default): int
    {
        $value = self::env($key);
        return $value === null ? $default : max(1, (int) $value);
    }

    /** PHP-FPM may run with clear_env, so fall back to $_ENV/$_SERVER; non-numeric is the default. */
    private static function intEnvWithFallback(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_numeric($value) ? (int) $value : $default;
    }
}
