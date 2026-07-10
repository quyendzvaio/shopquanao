<?php

class KnowledgeRetrieverTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private KnowledgeRetriever $retriever;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec("CREATE TABLE faqs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            category TEXT DEFAULT 'general',
            priority INTEGER DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE size_guides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            category_id INTEGER,
            size_name TEXT NOT NULL,
            height_from INTEGER,
            height_to INTEGER,
            weight_from INTEGER,
            weight_to INTEGER,
            description TEXT
        )");
        $this->pdo->exec("INSERT INTO faqs (question, answer, category, priority) VALUES
            ('Có đổi trả được không?', 'Đổi trả trong 7 ngày nếu sản phẩm còn nguyên tem mác.', 'return', 1),
            ('Phí ship thế nào?', 'Miễn phí ship đơn từ 500,000đ; dưới mức này tính phí theo khu vực.', 'shipping', 1)
        ");
        $this->retriever = new KnowledgeRetriever($this->pdo, ROOT_DIR, null);
    }

    public function testGetDocumentsLoadsMarkdownAndDatabaseKnowledge(): void
    {
        $docs = $this->retriever->getDocuments();
        $this->assertNotEmpty($docs);
        $this->assertNotEmpty(array_filter($docs, fn($d) => $d['source'] === 'db:faqs'));
        $this->assertNotEmpty(array_filter($docs, fn($d) => str_starts_with($d['source'], 'knowledge/')));
    }

    public function testLocalRetrievalFindsReturnPolicy(): void
    {
        $result = $this->retriever->search('shop đổi trả trong bao lâu', 'return', 3);
        $this->assertSame('local_fallback', $result['source']);
        $this->assertNotEmpty($result['results']);
        $joined = mb_strtolower(implode(' ', array_map(fn($r) => $r['title'] . ' ' . $r['content'], $result['results'])));
        $this->assertStringContainsString('7 ngày', $joined);
    }

    public function testEmbeddingHasStableVectorSize(): void
    {
        $a = $this->retriever->embed('đổi trả sản phẩm');
        $b = $this->retriever->embed('đổi trả sản phẩm');
        $this->assertCount(KnowledgeRetriever::VECTOR_SIZE, $a);
        $this->assertSame($a, $b);
    }
}
