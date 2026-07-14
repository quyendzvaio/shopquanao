<?php
/**
 * RAG knowledge retriever for shop policies, FAQ, size guide, and styling docs.
 *
 * Uses semantic embeddings from the rag-ml service, Qdrant vector search,
 * lexical keyword search, hybrid score merge, and cross-encoder reranking.
 * If Qdrant or rag-ml is unavailable, it falls back to Markdown + FAQ DB
 * lexical scoring so the chatbot can still answer from real shop data.
 */
class KnowledgeRetriever {
    public const COLLECTION = 'shop_knowledge_v2';
    public const VECTOR_SIZE = 768;
    private const DEFAULT_HYBRID_CANDIDATES = 12;
    private const VECTOR_WEIGHT = 0.65;
    private const LEXICAL_WEIGHT = 0.35;

    private PDO $pdo;
    private string $rootDir;
    private ?string $qdrantUrl;
    private ?string $ragMlUrl;
    private string $collection;
    private string $embeddingProvider;
    private string $embeddingModel;
    private int $hybridCandidates;

    public function __construct(PDO $pdo, ?string $rootDir = null, ?string $qdrantUrl = null, ?string $ragMlUrl = null) {
        $this->pdo = $pdo;
        $this->rootDir = $rootDir ?: dirname(__DIR__, 3);
        $envUrl = getenv('QDRANT_URL');
        $this->qdrantUrl = $qdrantUrl ?? (($envUrl !== false && $envUrl !== '') ? rtrim($envUrl, '/') : null);
        $envRagMlUrl = getenv('RAG_ML_URL');
        $this->ragMlUrl = $ragMlUrl ?? (($envRagMlUrl !== false && $envRagMlUrl !== '') ? rtrim($envRagMlUrl, '/') : null);
        $envCollection = getenv('KNOWLEDGE_COLLECTION');
        $this->collection = ($envCollection !== false && $envCollection !== '') ? $envCollection : self::COLLECTION;
        $envProvider = getenv('EMBEDDING_PROVIDER');
        $this->embeddingProvider = ($envProvider !== false && $envProvider !== '') ? $envProvider : 'rag_ml';
        $envModel = getenv('EMBEDDING_MODEL');
        $this->embeddingModel = ($envModel !== false && $envModel !== '') ? $envModel : 'bkai-foundation-models/vietnamese-bi-encoder';
        $envCandidates = getenv('KNOWLEDGE_HYBRID_CANDIDATES');
        $this->hybridCandidates = max(5, min(20, (int)(($envCandidates !== false && $envCandidates !== '') ? $envCandidates : self::DEFAULT_HYBRID_CANDIDATES)));
    }

    public function search(string $query, ?string $category = null, int $limit = 5): array {
        $query = trim($query);
        $limit = max(1, min(10, $limit));
        if ($query === '') {
            return ['results' => [], 'source' => 'none'];
        }

        $rewrite = $this->rewriteQuery($query, $category);
        $searchQuery = $rewrite['query'];

        $cacheKey = Cache::buildKey('kr', [
            'query' => mb_strtolower($searchQuery),
            'original_query' => mb_strtolower($query),
            'category' => (string)$category,
            'limit' => $limit,
            'collection' => $this->collection,
            'embedding_model' => $this->embeddingModel,
            'v' => 3,
        ]);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        $lexical = $this->searchLocal($searchQuery, $category, $this->hybridCandidates);
        $vector = $this->qdrantUrl !== null
            ? ($this->searchQdrant($searchQuery, $category, $this->hybridCandidates) ?? [])
            : [];

        if (empty($vector)) {
            $results = array_slice($this->withRetrievalMode($lexical, 'lexical_fallback'), 0, $limit);
            $result = [
                'results' => $results,
                'source' => 'lexical_fallback',
                'retrieval_mode' => 'lexical_fallback',
            ];
        } else {
            $merged = $this->mergeHybridCandidates($vector, $lexical);
            $reranked = $this->rerankKnowledge($searchQuery, $merged, $limit);
            $result = [
                'results' => $reranked['results'],
                'source' => $reranked['retrieval_mode'],
                'retrieval_mode' => $reranked['retrieval_mode'],
                'reranker_model' => $reranked['reranker_model'] ?? null,
            ];
        }

        $result['original_query'] = $query;
        $result['rewritten_query'] = $searchQuery;
        $result['query_rewrites'] = $rewrite['terms'];
        $result['embedding_model'] = $this->embeddingModel;
        $result['collection'] = $this->collection;
        Cache::set($cacheKey, $result, 600);
        return $result;
    }

    public function getDocuments(): array {
        $docs = array_merge($this->getMarkdownDocuments(), $this->getDatabaseDocuments());
        foreach ($docs as &$doc) {
            $doc['content'] = trim((string)($doc['content'] ?? ''));
            $doc['text'] = trim(($doc['title'] ?? '') . "\n" . $doc['content']);
        }
        unset($doc);
        return array_values(array_filter($docs, fn($d) => ($d['content'] ?? '') !== ''));
    }

    public function ensureQdrantCollection(): bool {
        if ($this->qdrantUrl === null) return false;
        $existing = $this->request('GET', '/collections/' . $this->collection, [], 10);
        if ($existing !== null) return true;

        $payload = [
            'vectors' => [
                'size' => self::VECTOR_SIZE,
                'distance' => 'Cosine',
            ],
        ];
        $response = $this->request('PUT', '/collections/' . $this->collection, $payload, 15);
        return $response !== null;
    }

    public function upsertToQdrant(array $documents): array {
        if ($this->qdrantUrl === null) {
            return ['success' => false, 'count' => 0, 'message' => 'QDRANT_URL is not configured'];
        }
        if (!$this->ensureQdrantCollection()) {
            return ['success' => false, 'count' => 0, 'message' => 'Unable to create or verify Qdrant collection'];
        }

        $points = [];
        foreach ($documents as $i => $doc) {
            $text = (string)($doc['text'] ?? (($doc['title'] ?? '') . "\n" . ($doc['content'] ?? '')));
            $id = $this->stablePointId($doc, $i);
            try {
                $vector = $this->embed($text);
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'count' => count($points),
                    'message' => 'Embedding failed: ' . $e->getMessage(),
                ];
            }
            $points[] = [
                'id' => $id,
                'vector' => $vector,
                'payload' => [
                    'doc_key' => $this->documentKey($doc),
                    'source' => $doc['source'] ?? 'unknown',
                    'title' => $doc['title'] ?? '',
                    'category' => $doc['category'] ?? 'general',
                    'content' => $doc['content'] ?? '',
                    'text' => $text,
                    'updated_at' => $doc['updated_at'] ?? date('c'),
                    'embedding_model' => $this->embeddingModel,
                ],
            ];
        }

        $response = $this->request('PUT', '/collections/' . $this->collection . '/points?wait=true', ['points' => $points], 60);
        return [
            'success' => $response !== null,
            'count' => count($points),
            'message' => $response !== null ? 'Knowledge indexed' : 'Qdrant upsert failed',
        ];
    }

    public function embed(string $text): array {
        $vector = $this->embedViaRagMl($text);
        if ($vector !== null) {
            return $vector;
        }
        if ($this->ragMlUrl !== null && $this->embeddingProvider !== 'local_hash') {
            throw new RuntimeException('rag-ml embedding service is unavailable');
        }
        return $this->localHashEmbedding($text);
    }

    private function localHashEmbedding(string $text): array {
        $vector = array_fill(0, self::VECTOR_SIZE, 0.0);
        $tokens = $this->tokens($text);
        foreach ($tokens as $token) {
            $hash = crc32($token);
            $idx = $hash % self::VECTOR_SIZE;
            $sign = (($hash >> 8) & 1) === 1 ? 1.0 : -1.0;
            $vector[$idx] += $sign;
        }

        $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));
        if ($norm <= 0) return $vector;
        return array_map(fn($v) => round($v / $norm, 6), $vector);
    }

    private function searchQdrant(string $query, ?string $category, int $limit): ?array {
        try {
            $vector = $this->embed($query);
        } catch (Throwable $e) {
            error_log('Knowledge vector embedding failed: ' . $e->getMessage());
            return null;
        }

        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'score_threshold' => 0.05,
        ];
        if ($category !== null && $category !== '') {
            $payload['filter'] = [
                'must' => [[
                    'key' => 'category',
                    'match' => ['value' => $category],
                ]],
            ];
        }

        $response = $this->request('POST', '/collections/' . $this->collection . '/points/search', $payload, 5);
        if (!is_array($response) || !isset($response['result']) || !is_array($response['result'])) return null;

        $results = [];
        foreach ($response['result'] as $item) {
            $payload = $item['payload'] ?? [];
            $results[] = [
                'title' => (string)($payload['title'] ?? ''),
                'content' => (string)($payload['content'] ?? ''),
                'category' => (string)($payload['category'] ?? 'general'),
                'source' => (string)($payload['source'] ?? 'qdrant'),
                'doc_key' => (string)($payload['doc_key'] ?? ''),
                'score' => (float)($item['score'] ?? 0),
                'vector_score' => (float)($item['score'] ?? 0),
                'lexical_score' => 0.0,
                'hybrid_score' => 0.0,
                'rerank_score' => null,
                'updated_at' => $payload['updated_at'] ?? null,
            ];
        }

        return $results;
    }

    private function searchLocal(string $query, ?string $category, int $limit): array {
        $queryTokens = array_unique($this->tokens($query));
        $docs = $this->getDocuments();
        $scored = [];

        foreach ($docs as $doc) {
            if ($category !== null && $category !== '' && ($doc['category'] ?? '') !== $category) continue;
            $text = mb_strtolower((string)$doc['text']);
            $score = 0.0;
            foreach ($queryTokens as $token) {
                if (mb_strpos($text, $token) !== false) {
                    $score += mb_strlen($token) >= 5 ? 2.0 : 1.0;
                }
            }
            if ($score <= 0 && $this->hasIntentOverlap($query, $doc)) $score = 0.5;
            if ($score > 0) {
                $doc['score'] = $score;
                $doc['doc_key'] = $this->documentKey($doc);
                $scored[] = $doc;
            }
        }

        usort($scored, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_map(fn($d) => [
            'title' => $d['title'],
            'content' => $d['content'],
            'category' => $d['category'],
            'source' => $d['source'],
            'doc_key' => $d['doc_key'] ?? $this->documentKey($d),
            'score' => (float)$d['score'],
            'vector_score' => 0.0,
            'lexical_score' => (float)$d['score'],
            'hybrid_score' => 0.0,
            'rerank_score' => null,
            'updated_at' => $d['updated_at'] ?? null,
        ], array_slice($scored, 0, $limit));
    }

    private function mergeHybridCandidates(array $vector, array $lexical): array {
        $maxVector = max(1.0, ...array_map(fn($d) => (float)($d['vector_score'] ?? $d['score'] ?? 0), $vector ?: [['score' => 0]]));
        $maxLexical = max(1.0, ...array_map(fn($d) => (float)($d['lexical_score'] ?? $d['score'] ?? 0), $lexical ?: [['score' => 0]]));
        $merged = [];

        foreach ($vector as $doc) {
            $key = $doc['doc_key'] ?: $this->documentKey($doc);
            $doc['doc_key'] = $key;
            $doc['vector_score'] = (float)($doc['vector_score'] ?? $doc['score'] ?? 0);
            $doc['lexical_score'] = 0.0;
            $merged[$key] = $doc;
        }

        foreach ($lexical as $doc) {
            $key = $doc['doc_key'] ?: $this->documentKey($doc);
            if (!isset($merged[$key])) {
                $doc['doc_key'] = $key;
                $doc['vector_score'] = 0.0;
                $merged[$key] = $doc;
            }
            $merged[$key]['lexical_score'] = max(
                (float)($merged[$key]['lexical_score'] ?? 0),
                (float)($doc['lexical_score'] ?? $doc['score'] ?? 0)
            );
        }

        foreach ($merged as &$doc) {
            $vectorNorm = max(0.0, min(1.0, (float)($doc['vector_score'] ?? 0) / $maxVector));
            $lexicalNorm = max(0.0, min(1.0, (float)($doc['lexical_score'] ?? 0) / $maxLexical));
            $doc['vector_score'] = round((float)($doc['vector_score'] ?? 0), 6);
            $doc['lexical_score'] = round((float)($doc['lexical_score'] ?? 0), 6);
            $doc['hybrid_score'] = round((self::VECTOR_WEIGHT * $vectorNorm) + (self::LEXICAL_WEIGHT * $lexicalNorm), 6);
            $doc['score'] = $doc['hybrid_score'];
            $doc['rerank_score'] = null;
        }
        unset($doc);

        $candidates = array_values($merged);
        usort($candidates, fn($a, $b) => ($b['hybrid_score'] <=> $a['hybrid_score']));
        return array_slice($candidates, 0, $this->hybridCandidates);
    }

    private function rerankKnowledge(string $query, array $candidates, int $limit): array {
        if (empty($candidates)) {
            return ['results' => [], 'retrieval_mode' => 'hybrid_no_results'];
        }

        $texts = array_map(fn($d) => trim(($d['title'] ?? '') . "\n" . ($d['content'] ?? '')), $candidates);
        $payload = ['query' => $query, 'texts' => $texts];
        $response = $this->requestRagMl('/rerank', $payload, $this->ragMlTimeout('RAG_RERANK_TIMEOUT', 6));
        if (!is_array($response) || !isset($response['sorted_indices']) || !is_array($response['sorted_indices'])) {
            return [
                'results' => array_slice($this->withRetrievalMode($candidates, 'hybrid_no_rerank'), 0, $limit),
                'retrieval_mode' => 'hybrid_no_rerank',
            ];
        }

        $scores = is_array($response['scores'] ?? null) ? $response['scores'] : [];
        $reranked = [];
        foreach ($response['sorted_indices'] as $idx) {
            $idx = (int)$idx;
            if (!isset($candidates[$idx])) continue;
            $doc = $candidates[$idx];
            $doc['rerank_score'] = isset($scores[$idx]) ? round((float)$scores[$idx], 6) : null;
            $doc['score'] = $doc['rerank_score'] ?? $doc['hybrid_score'];
            $reranked[] = $doc;
        }

        return [
            'results' => array_slice($this->withRetrievalMode($reranked, 'hybrid_reranked'), 0, $limit),
            'retrieval_mode' => 'hybrid_reranked',
            'reranker_model' => $response['model'] ?? null,
        ];
    }

    private function withRetrievalMode(array $results, string $mode): array {
        foreach ($results as &$result) {
            $result['retrieval_mode'] = $mode;
        }
        unset($result);
        return $results;
    }

    private function embedViaRagMl(string $text): ?array {
        if ($this->ragMlUrl === null || $this->embeddingProvider === 'local_hash') {
            return null;
        }
        $response = $this->requestRagMl('/embed', ['texts' => [$text]], $this->ragMlTimeout('RAG_EMBED_TIMEOUT', 8));
        if (!is_array($response) || empty($response['embeddings'][0]) || !is_array($response['embeddings'][0])) {
            return null;
        }
        $vector = array_map('floatval', $response['embeddings'][0]);
        return count($vector) === self::VECTOR_SIZE ? $vector : null;
    }

    private function requestRagMl(string $path, array $payload, int $timeout): ?array {
        if ($this->ragMlUrl === null) return null;
        $ch = curl_init($this->ragMlUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '' || $httpCode >= 400) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function ragMlTimeout(string $key, int $default): int {
        $value = getenv($key);
        if ($value === false || $value === '') return $default;
        return max(1, (int)$value);
    }

    private function documentKey(array $doc): string {
        $source = (string)($doc['source'] ?? '');
        $title = (string)($doc['title'] ?? '');
        $content = mb_substr((string)($doc['content'] ?? ''), 0, 120);
        return sha1($source . '|' . $title . '|' . $content);
    }

    private function getMarkdownDocuments(): array {
        $dir = $this->rootDir . '/knowledge';
        if (!is_dir($dir)) return [];

        $docs = [];
        foreach (glob($dir . '/*.md') ?: [] as $path) {
            $category = $this->categoryFromFilename(basename($path));
            $content = file_get_contents($path);
            if ($content === false) continue;
            foreach ($this->chunkMarkdown($content) as $chunk) {
                $docs[] = [
                    'source' => 'knowledge/' . basename($path),
                    'title' => $chunk['title'],
                    'category' => $chunk['category'] ?: $category,
                    'content' => $chunk['content'],
                    'updated_at' => date('c', filemtime($path) ?: time()),
                ];
            }
        }
        return $docs;
    }

    private function getDatabaseDocuments(): array {
        $docs = [];
        try {
            $rows = $this->pdo->query("SELECT question, answer, category FROM faqs")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $docs[] = [
                    'source' => 'db:faqs',
                    'title' => (string)$row['question'],
                    'category' => (string)($row['category'] ?? 'general'),
                    'content' => (string)$row['answer'],
                    'updated_at' => null,
                ];
            }
        } catch (Throwable $e) {}

        try {
            $rows = $this->pdo->query("SELECT size_name, category_id, height_from, height_to, weight_from, weight_to, description FROM size_guides")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $docs[] = [
                    'source' => 'db:size_guides',
                    'title' => 'Hướng dẫn size ' . $row['size_name'],
                    'category' => 'size',
                    'content' => trim(($row['description'] ?? '') . ' Cao ' . ($row['height_from'] ?? '') . '-' . ($row['height_to'] ?? '') . 'cm, nặng ' . ($row['weight_from'] ?? '') . '-' . ($row['weight_to'] ?? '') . 'kg.'),
                    'updated_at' => null,
                ];
            }
        } catch (Throwable $e) {}

        return $docs;
    }

    private function chunkMarkdown(string $markdown): array {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $chunks = [];
        $currentTitle = '';
        $current = [];
        $currentCategory = '';

        $flush = function() use (&$chunks, &$currentTitle, &$current, &$currentCategory): void {
            $content = trim(implode("\n", $current));
            if ($currentTitle !== '' && $content !== '') {
                $chunks[] = [
                    'title' => $currentTitle,
                    'category' => $currentCategory,
                    'content' => $content,
                ];
            }
            $current = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/u', $line, $m)) {
                $flush();
                $currentTitle = trim($m[1]);
                $currentCategory = $this->categoryFromTitle($currentTitle);
                continue;
            }
            if ($currentTitle !== '') $current[] = $line;
        }
        $flush();

        return $chunks;
    }

    private function tokens(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $raw = preg_split('/\s+/u', $text) ?: [];
        return array_values(array_filter($raw, fn($t) => mb_strlen($t) >= 2));
    }

    private function rewriteQuery(string $query, ?string $category): array {
        $normalized = $this->normalizeVietnameseQuery($query);
        $terms = [];

        $synonyms = [
            'return' => ['đổi trả', 'đổi size', 'đổi màu', 'hoàn tiền', 'trả hàng', 'tem mác', 'chưa qua sử dụng', 'sale trên 50%', 'phí vận chuyển hai chiều'],
            'shipping' => ['giao hàng', 'phí ship', 'vận chuyển', 'nội thành', 'ngoại tỉnh', 'miễn phí ship', 'đơn từ 500000'],
            'payment' => ['thanh toán', 'cod', 'chuyển khoản', 'momo', 'vnpay'],
            'warranty' => ['bảo hành', 'sản phẩm lỗi', 'lỗi đường may', 'giao sai mẫu', 'giao sai size'],
            'wholesale' => ['bán sỉ', 'bán buôn', 'đơn số lượng lớn'],
            'order' => ['đơn hàng', 'trạng thái đơn', 'theo dõi đơn hàng'],
            'size' => ['bảng size', 'chiều cao', 'cân nặng', 'chọn size', 'kích cỡ'],
            'shop_info' => ['thông tin shop', 'địa chỉ', 'hotline', 'giờ mở cửa'],
        ];

        $intentMap = [
            'return' => ['đổi', 'doi', 'trả', 'tra', 'hoàn', 'hoan', 'refund', 'return', 'sale', 'không vừa', 'khong vua', 'lỗi', 'loi'],
            'shipping' => ['ship', 'giao', 'vận chuyển', 'van chuyen', 'nội thành', 'ngoại tỉnh', 'mien phi', 'free ship'],
            'payment' => ['thanh toán', 'thanh toan', 'cod', 'momo', 'vnpay', 'chuyển khoản', 'chuyen khoan'],
            'warranty' => ['bảo hành', 'bao hanh', 'lỗi', 'loi', 'đường may', 'duong may', 'rách', 'rach'],
            'wholesale' => ['bán sỉ', 'ban si', 'bán buôn', 'ban buon', 'sỉ', 'si'],
            'order' => ['đơn hàng', 'don hang', 'trạng thái', 'trang thai', 'theo dõi', 'tracking'],
            'size' => ['size', 'kích cỡ', 'kich co', 'cao', 'nặng', 'kg', 'cm'],
            'shop_info' => ['địa chỉ', 'dia chi', 'hotline', 'cửa hàng', 'shop', 'giờ mở cửa'],
        ];

        if ($category !== null && isset($synonyms[$category])) {
            $terms = array_merge($terms, $synonyms[$category]);
        }

        foreach ($intentMap as $intent => $needles) {
            foreach ($needles as $needle) {
                if (mb_strpos($normalized, $needle) !== false && isset($synonyms[$intent])) {
                    $terms = array_merge($terms, $synonyms[$intent]);
                    break;
                }
            }
        }

        $terms = array_values(array_unique(array_filter($terms)));
        $rewritten = trim($query . ' ' . implode(' ', $terms));

        return [
            'query' => $rewritten !== '' ? $rewritten : $query,
            'terms' => $terms,
        ];
    }

    private function normalizeVietnameseQuery(string $query): string {
        $text = mb_strtolower($query);
        $map = [
            'đ' => 'd',
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
        ];
        return strtr($text, $map);
    }

    private function hasIntentOverlap(string $query, array $doc): bool {
        $q = mb_strtolower($query);
        $cat = (string)($doc['category'] ?? '');
        $map = [
            'return' => ['đổi', 'trả', 'hoàn', 'lỗi', 'tem'],
            'shipping' => ['ship', 'giao', 'vận chuyển'],
            'payment' => ['thanh toán', 'cod', 'momo', 'vnpay'],
            'warranty' => ['bảo hành', 'lỗi'],
            'size' => ['size', 'cỡ', 'cao', 'nặng'],
            'outfit' => ['phối', 'mặc với', 'kết hợp'],
        ];
        foreach ($map[$cat] ?? [] as $needle) {
            if (mb_strpos($q, $needle) !== false) return true;
        }
        return false;
    }

    private function categoryFromFilename(string $filename): string {
        return match ($filename) {
            'faq.md' => 'general',
            'policies.md' => 'policy',
            'shop-info.md' => 'shop_info',
            'size-guide.md' => 'size',
            'outfit-tips.md' => 'outfit',
            default => 'general',
        };
    }

    private function categoryFromTitle(string $title): string {
        $t = mb_strtolower($title);
        if (str_contains($t, 'giao') || str_contains($t, 'vận chuyển') || str_contains($t, 'ship')) return 'shipping';
        if (str_contains($t, 'đổi') || str_contains($t, 'trả')) return 'return';
        if (str_contains($t, 'bảo hành')) return 'warranty';
        if (str_contains($t, 'thanh toán')) return 'payment';
        if (str_contains($t, 'bán sỉ')) return 'wholesale';
        if (str_contains($t, 'đơn hàng')) return 'order';
        if (str_contains($t, 'size')) return 'size';
        if (str_contains($t, 'phối') || str_contains($t, 'streetstyle')) return 'outfit';
        if (str_contains($t, 'cửa hàng') || str_contains($t, 'fashion shop')) return 'shop_info';
        return '';
    }

    private function stablePointId(array $doc, int $index): string {
        $hash = hash('sha256', ($doc['source'] ?? '') . '|' . ($doc['title'] ?? '') . '|' . $index);
        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
    }

    private function request(string $method, string $path, array $payload, int $timeout): ?array {
        if ($this->qdrantUrl === null) return null;
        $ch = curl_init($this->qdrantUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '' || $httpCode >= 400) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
