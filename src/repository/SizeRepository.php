<?php

/** Reads product_sizes (legacy per-product sizes) and size_guides. */
final class SizeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<int|string,mixed>> every product_sizes column of one product */
    public function sizesForProduct(int $productId, int $fetchMode = PDO::FETCH_ASSOC): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetchAll($fetchMode);
    }

    /**
     * @param list<int> $productIds non-empty
     * @return list<array{product_id:mixed,size_name:mixed}>
     */
    public function sizesForProducts(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare("SELECT product_id, size_name FROM product_sizes WHERE product_id IN ($placeholders)");
        $stmt->execute($productIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{product_id:mixed,size_name:mixed}> every legacy size, by product then insertion */
    public function allProductSizes(): array
    {
        return $this->pdo->query(
            'SELECT product_id, size_name FROM product_sizes ORDER BY product_id, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<int|string,mixed>> the REST size guide of one category, S-M-L-XL (MariaDB only, PDO default fetch mode) */
    public function guidesForCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM size_guides WHERE category_id = ? ORDER BY FIELD(size_name, 'S','M','L','XL')");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<int|string,mixed>> the REST size guide of every category (MariaDB only, PDO default fetch mode) */
    public function allGuides(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM size_guides ORDER BY category_id, FIELD(size_name, 'S','M','L','XL')");
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> the size guide columns the knowledge documents are built from */
    public function allForKnowledge(): array
    {
        return $this->pdo->query("SELECT size_name, category_id, height_from, height_to, weight_from, weight_to, description FROM size_guides")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> size guide rows of one category for the size suggestion */
    public function suggestionGuidesForCategory(int $categoryId): array
    {
        $orderBy = $this->suggestionOrderBy();
        $stmt = $this->pdo->prepare("SELECT * FROM size_guides WHERE category_id = ? $orderBy");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> size guide rows of every category for the size suggestion */
    public function allSuggestionGuides(): array
    {
        $orderBy = $this->suggestionOrderBy();
        $stmt = $this->pdo->query("SELECT * FROM size_guides $orderBy");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** SQLite has no FIELD(), so the size order is spelled out as a CASE there. */
    private function suggestionOrderBy(): string
    {
        return $this->isSqlite()
            ? "ORDER BY category_id, CASE size_name WHEN 'S' THEN 1 WHEN 'M' THEN 2 WHEN 'L' THEN 3 WHEN 'XL' THEN 4 ELSE 5 END"
            : "ORDER BY category_id, FIELD(size_name, 'S','M','L','XL')";
    }

    private function isSqlite(): bool {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }
}
