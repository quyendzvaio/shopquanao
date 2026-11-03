<?php

/** Reads the faqs table. */
final class FaqRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<int|string,mixed>> FAQs optionally filtered by category and a question/answer search, by priority (PDO default fetch mode) */
    public function search(?string $category, string $search): array
    {
        $sql = "SELECT * FROM faqs WHERE 1=1";
        $params = [];

        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($search) {
            $sql .= " AND (question LIKE ? OR answer LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY priority, id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> question, answer and category of every FAQ, for the knowledge documents */
    public function allForKnowledge(): array
    {
        return $this->pdo->query("SELECT question, answer, category FROM faqs")->fetchAll(PDO::FETCH_ASSOC);
    }
}
