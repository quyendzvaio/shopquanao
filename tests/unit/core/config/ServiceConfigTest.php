<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../agent/EnvSandbox.php';

final class ServiceConfigTest extends TestCase
{
    use EnvSandbox;

    protected function setUp(): void
    {
        $this->cleanEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testDefaultsMatchPreviousEnvFallbacks(): void
    {
        $config = ServiceConfig::fromEnvironment();

        $this->assertNull($config->qdrantUrl);
        $this->assertNull($config->ragMlUrl);
        $this->assertSame('shop_knowledge_v2', $config->knowledgeCollection);
        $this->assertSame('rag_ml', $config->embeddingProvider);
        $this->assertSame('bkai-foundation-models/vietnamese-bi-encoder', $config->embeddingModel);
        $this->assertSame(12, $config->knowledgeHybridCandidates);
        $this->assertSame('v1', $config->knowledgeVersion);
        $this->assertSame('itdainb/PhoRanker', $config->knowledgeRerankerModel);
        $this->assertSame(6, $config->ragRerankTimeout);
        $this->assertSame(8, $config->ragEmbedTimeout);
        $this->assertSame('http://reranker:8000', $config->rerankerUrl);
        $this->assertSame(500000, $config->freeShippingThreshold);
        $this->assertSame(200000, $config->freeshipNudgeMaxGap);
    }

    public function testReadsEachVariable(): void
    {
        $values = [
            'QDRANT_URL' => 'http://qdrant:6333/',
            'RAG_ML_URL' => 'http://rag-ml:8001/',
            'KNOWLEDGE_COLLECTION' => 'kc',
            'EMBEDDING_PROVIDER' => 'local_hash',
            'EMBEDDING_MODEL' => 'em',
            'KNOWLEDGE_HYBRID_CANDIDATES' => '7',
            'KNOWLEDGE_VERSION' => 'v9',
            'KNOWLEDGE_RERANKER_MODEL' => 'rm',
            'RAG_RERANK_TIMEOUT' => '3',
            'RAG_EMBED_TIMEOUT' => '4',
            'RERANKER_URL' => 'http://rr:9000/',
            'FREE_SHIPPING_THRESHOLD' => '600000',
            'FREESHIP_NUDGE_MAX_GAP' => '150000',
        ];
        foreach ($values as $key => $value) {
            $this->setEnv($key, $value);
        }

        $config = ServiceConfig::fromEnvironment();

        $this->assertSame('http://qdrant:6333', $config->qdrantUrl);
        $this->assertSame('http://rag-ml:8001', $config->ragMlUrl);
        $this->assertSame('kc', $config->knowledgeCollection);
        $this->assertSame('local_hash', $config->embeddingProvider);
        $this->assertSame('em', $config->embeddingModel);
        $this->assertSame(7, $config->knowledgeHybridCandidates);
        $this->assertSame('v9', $config->knowledgeVersion);
        $this->assertSame('rm', $config->knowledgeRerankerModel);
        $this->assertSame(3, $config->ragRerankTimeout);
        $this->assertSame(4, $config->ragEmbedTimeout);
        $this->assertSame('http://rr:9000/', $config->rerankerUrl);
        $this->assertSame(600000, $config->freeShippingThreshold);
        $this->assertSame(150000, $config->freeshipNudgeMaxGap);
    }

    public function testKeepsEachKeysPreviousLookupSemantics(): void
    {
        // getenv-only keys: an empty value is the default, a falsy "0" too where the old code used ?:
        $this->setEnv('KNOWLEDGE_COLLECTION', '');
        $this->setEnv('KNOWLEDGE_VERSION', '0');
        $this->setEnv('KNOWLEDGE_RERANKER_MODEL', '0');
        $this->setEnv('KNOWLEDGE_HYBRID_CANDIDATES', 'abc');
        $this->setEnv('RAG_RERANK_TIMEOUT', '0');
        $this->setEnv('RERANKER_URL', '');
        $_ENV['QDRANT_URL'] = 'http://only-in-env-array';
        // free-shipping keys fall back getenv -> $_ENV -> $_SERVER; non-numeric -> default; negative -> 0
        putenv('FREE_SHIPPING_THRESHOLD');
        $_SERVER['FREE_SHIPPING_THRESHOLD'] = '700000';
        $this->setEnv('FREESHIP_NUDGE_MAX_GAP', '-5');

        $config = ServiceConfig::fromEnvironment();

        $this->assertSame('shop_knowledge_v2', $config->knowledgeCollection);
        $this->assertSame('v1', $config->knowledgeVersion);
        $this->assertSame('itdainb/PhoRanker', $config->knowledgeRerankerModel);
        $this->assertSame(0, $config->knowledgeHybridCandidates);
        $this->assertSame(1, $config->ragRerankTimeout);
        $this->assertSame('http://reranker:8000', $config->rerankerUrl);
        $this->assertNull($config->qdrantUrl);
        $this->assertSame(700000, $config->freeShippingThreshold);
        $this->assertSame(0, $config->freeshipNudgeMaxGap);

        $this->setEnv('FREESHIP_NUDGE_MAX_GAP', '200k');
        $this->assertSame(200000, ServiceConfig::fromEnvironment()->freeshipNudgeMaxGap);
    }
}
