<?php

/** Reads the legacy outfit_suggestions table (hand-made product pairs); the UC-A catalogue lives in OutfitCatalogRepository. */
final class OutfitSuggestionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<int|string,mixed>> suggestions with both products' names and prices, optionally filtered (PDO default fetch mode) */
    public function suggestions(?int $productId, string $search): array
    {
        $sql = "
    SELECT o.*, p1.name as product_name, p1.price as product_price,
           p2.name as paired_name, p2.price as paired_price, p2.image as paired_image
    FROM outfit_suggestions o
    JOIN products p1 ON o.product_id = p1.id
    JOIN products p2 ON o.paired_product_id = p2.id
    WHERE 1=1
";
        $params = [];

        if ($productId) {
            $sql .= " AND (o.product_id = ? OR o.paired_product_id = ?)";
            $params[] = $productId;
            $params[] = $productId;
        }

        if ($search) {
            $sql .= " AND (p1.name LIKE ? OR p2.name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY o.id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
