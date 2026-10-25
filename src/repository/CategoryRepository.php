<?php

/** Reads categories and product_subcategories. */
final class CategoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> every categories column, by id */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM categories ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> id, name, canonical_key and family of every category, by id */
    public function taxonomyList(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, canonical_key, family FROM categories ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<int|string,mixed>> every subcategory with its category name (PDO default fetch mode) */
    public function subcategoriesWithCategory(): array
    {
        return $this->pdo->query(
            "SELECT sc.id, sc.category_id, sc.display_name, c.name AS category_name
     FROM product_subcategories sc JOIN categories c ON c.id = sc.category_id ORDER BY c.id, sc.id"
        )->fetchAll();
    }
}
