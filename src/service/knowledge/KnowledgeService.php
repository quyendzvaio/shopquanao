<?php


/** Application-service boundary for the hybrid RAG knowledge retrieval. */
final class KnowledgeService
{
    private KnowledgeRetriever $knowledgeRetriever;

    public function __construct(PDO $pdo, ServiceConfig $config)
    {
        $this->knowledgeRetriever = new KnowledgeRetriever($pdo, $config);
    }

    public function retrieve(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') return ['results' => [], 'message' => 'Query is required'];
        $category = isset($args['category']) && $args['category'] !== '' ? (string)$args['category'] : null;
        if (preg_match('/hoàn tiền|refund/ui', $query)) {
            $category = 'policy';
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
        $result = $this->knowledgeRetriever->search($query, $category, $limit);
        $result['guidance'] = 'Chỉ trả lời dựa trên results. Nếu results rỗng hoặc chưa đủ, hãy nói chưa có đủ thông tin trong dữ liệu shop và hỏi thêm.';
        return $result;
    }
}
