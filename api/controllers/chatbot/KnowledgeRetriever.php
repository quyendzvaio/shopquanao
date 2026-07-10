<?php
/**
 * RAG knowledge retriever for shop policies, FAQ, size guide, and styling docs.
 *
 * Uses Qdrant when available, with a deterministic local embedding fallback.
 * If Qdrant is not configured or unavailable, it falls back to Markdown + FAQ DB
 * keyword scoring so the chatbot can still answer from real shop data.
 */
class KnowledgeRetriever {
    public const COLLECTION = 'shop_knowledge';
    public const VECTOR_SIZE = 256;

    private PDO $pdo;
    private string $rootDir;
    private ?string $qdrantUrl;

    public function __construct(PDO $pdo, ?string $rootDir = null, ?string $qdrantUrl = null) {
        $this->pdo = $pdo;
        $this->rootDir = $rootDir ?: dirname(__DIR__, 3);
        $envUrl = getenv('QDRANT_URL');
        $this->qdrantUrl = $qdrantUrl ?? (($envUrl !== false && $envUrl !== '') ? rtrim($envUrl, '/') : null);
    }

    public function search(string $query, ?string $category = null, int $limit = 5): array {
        $query = trim($query);
        $limit = max(1, min(10, $limit));
        if ($query === '') {
            return ['results' => [], 'source' => 'none'];
        }

        $cacheKey = Cache::buildKey('kr', [
            'query' => mb_strtolower($query),
            'category' => (string)$category,
            'limit' => $limit,
            'v' => 1,
        ]);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        $result = null;
        if ($this->qdrantUrl !== null) {
            $result = $this->searchQdrant($query, $category, $limit);
        }

        if ($result === null || empty($result['results'])) {
            $result = [
                'results' => $this->searchLocal($query, $category, $limit),
                'source' => 'local_fallback',
            ];
        }

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
        $existing = $this->request('GET', '/collections/' . self::COLLECTION, [], 10);
        if ($existing !== null) return true;

        $payload = [
            'vectors' => [
                'size' => self::VECTOR_SIZE,
                'distance' => 'Cosine',
            ],
        ];
        $response = $this->request('PUT', '/collections/' . self::COLLECTION, $payload, 15);
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
            $points[] = [
                'id' => $id,
                'vector' => $this->embed($text),
                'payload' => [
                    'source' => $doc['source'] ?? 'unknown',
                    'title' => $doc['title'] ?? '',
                    'category' => $doc['category'] ?? 'general',
                    'content' => $doc['content'] ?? '',
                    'updated_at' => $doc['updated_at'] ?? date('c'),
                ],
            ];
        }

        $response = $this->request('PUT', '/collections/' . self::COLLECTION . '/points?wait=true', ['points' => $points], 60);
        return [
            'success' => $response !== null,
            'count' => count($points),
            'message' => $response !== null ? 'Knowledge indexed' : 'Qdrant upsert failed',
        ];
    }

    public function embed(string $text): array {
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
        $payload = [
            'vector' => $this->embed($query),
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

        $response = $this->request('POST', '/collections/' . self::COLLECTION . '/points/search', $payload, 5);
        if (!is_array($response) || !isset($response['result']) || !is_array($response['result'])) return null;

        $results = [];
        foreach ($response['result'] as $item) {
            $payload = $item['payload'] ?? [];
            $results[] = [
                'title' => (string)($payload['title'] ?? ''),
                'content' => (string)($payload['content'] ?? ''),
                'category' => (string)($payload['category'] ?? 'general'),
                'source' => (string)($payload['source'] ?? 'qdrant'),
                'score' => (float)($item['score'] ?? 0),
                'updated_at' => $payload['updated_at'] ?? null,
            ];
        }

        return ['results' => $results, 'source' => 'qdrant'];
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
                $scored[] = $doc;
            }
        }

        usort($scored, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_map(fn($d) => [
            'title' => $d['title'],
            'content' => $d['content'],
            'category' => $d['category'],
            'source' => $d['source'],
            'score' => (float)$d['score'],
            'updated_at' => $d['updated_at'] ?? null,
        ], array_slice($scored, 0, $limit));
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
