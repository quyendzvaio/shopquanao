<?php


/** Reranks product search results via the Python TF-IDF sidecar; falls back to original order. */
final class ProductReranker
{
    /** Tối thiểu bao nhiêu kết quả thì kích hoạt rerank */
    private const RERANK_MIN_RESULTS = 5;

    /** Timeout cho reranker sidecar (ms) — fallback về gốc nếu quá chậm */
    private const RERANK_TIMEOUT_MS = 2000;
    /** Tối đa bao nhiêu items gửi xuống reranker (phần còn lại giữ nguyên thứ tự) */
    private const RERANK_MAX_ITEMS = 20;

    /** @param ?string $url sidecar base URL (`/rerank` is appended); null disables the reranker */
    public function __construct(private ?string $url)
    {
    }

    /**
     * Apply reranker sidecar nếu có đủ kết quả.
     * Fallback về thứ tự gốc nếu reranker không phản hồi.
     */
    public function apply(string $query, array $result): array {
        if (count($result['products']) < self::RERANK_MIN_RESULTS) {
            return $result; // quá ít, không cần rerank
        }

        // Limit items gửi xuống reranker để tránh timeout
        $productsToRerank = array_slice($result["products"], 0, self::RERANK_MAX_ITEMS);

        $texts = array_map(fn($p) => $p['name'] ?? '', $productsToRerank);
        $texts = array_values($texts); // re-index

        try {
            $sorted = $this->callReranker($query, $texts);
            if ($sorted === null) {
                return $result; // fallback
            }

            // Sắp xếp lại products theo sorted indices (chỉ phần được rerank)
            $reranked = [];
            $rerankedKeys = [];
            foreach ($sorted as $idx) {
                if (isset($productsToRerank[$idx])) {
                    $key = array_search($productsToRerank[$idx], $result["products"], true);
                    if ($key !== false) {
                        $rerankedKeys[$key] = true;
                        $reranked[] = $result["products"][$key];
                    }
                }
            }

            // Giữ lại các sản phẩm không được rerank (giữ nguyên thứ tự)
            foreach ($result["products"] as $i => $p) {
                if (!isset($rerankedKeys[$i])) {
                    $reranked[] = $p;
                }
            }

            $result["products"] = $reranked;
        } catch (Throwable $e) {
            error_log("Reranker fallback: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Gọi Python sidecar reranker.
     * Trả về mảng indices đã sorted theo relevance (cao→thấp), hoặc null nếu lỗi.
     */
    private function callReranker(string $query, array $texts): ?array {
        $url = $this->getRerankerUrl();
        if ($url === null) return null;

        $payload = json_encode([
            'query' => $query,
            'texts' => $texts,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => intdiv(self::RERANK_TIMEOUT_MS, 1000) ?: 1, // 2 second max
            CURLOPT_CONNECTTIMEOUT => 1, // 1 second connect
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($raw === false || $raw === '' || $httpCode !== 200) {
            $body = $raw === false ? '' : $raw;
            error_log("Reranker HTTP $httpCode, body=" . substr($body, 0, 200));
            return null;
        }

        $data = json_decode($raw, true);
        if (!isset($data['sorted_indices']) || !is_array($data['sorted_indices'])) {
            return null;
        }

        // Log latency để monitoring
        if (isset($data['elapsed_ms'])) {
            error_log("Reranker latency: {$data['elapsed_ms']}ms for " . count($texts) . " items");
        }

        return $data['sorted_indices'];
    }

    /**
     * URL /rerank của reranker sidecar (cấu hình qua ServiceConfig), hoặc null khi tắt.
     */
    private function getRerankerUrl(): ?string {
        return $this->url === null ? null : rtrim($this->url, '/') . '/rerank';
    }
}
