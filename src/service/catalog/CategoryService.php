<?php

/** Category and subcategory lists for the REST API, the agent tools and the admin forms. */
final class CategoryService
{
    private CategoryRepository $categories;

    public function __construct(PDO $pdo)
    {
        $this->categories = new CategoryRepository($pdo);
    }

    /** @return list<array<string,mixed>> every categories row, by id (GET /api/categories) */
    public function all(): array
    {
        return $this->categories->allOrdered();
    }

    /** @return list<array<int|string,mixed>> every categories row in table order (the home page filter bar) */
    public function homeFilter(): array
    {
        return $this->categories->allForHomeFilter();
    }

    /** @return list<array{id:int,name:string,canonical_key:string,family:string}> typed taxonomy, by id */
    public function taxonomy(): array
    {
        return array_map(
            fn($category) => [
                'id' => (int)$category['id'],
                'name' => (string)$category['name'],
                'canonical_key' => (string)$category['canonical_key'],
                'family' => (string)$category['family'],
            ],
            $this->categories->taxonomyList()
        );
    }

    /** @return list<array<int|string,mixed>> every subcategory with its category name, by category then id */
    public function withSubcategories(): array
    {
        return $this->categories->subcategoriesWithCategory();
    }
}
