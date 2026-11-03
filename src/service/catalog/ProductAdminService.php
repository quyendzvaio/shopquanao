<?php

/** Admin writes on the products table: create, partial update, delete and the newest-first list. */
final class ProductAdminService
{
    private ProductRepository $products;

    public function __construct(PDO $pdo)
    {
        $this->products = new ProductRepository($pdo);
    }

    /**
     * @param array<string,mixed> $fields name, price, stock, description, category_id, subcategory_id, image
     *        and style_tags (a list of tags, stored as a column string)
     * @return int the new product id
     */
    public function create(array $fields): int
    {
        $fields['style_tags'] = StyleTag::toColumn((array)($fields['style_tags'] ?? []));
        return $this->products->insert($fields);
    }

    /**
     * @param array<string,mixed> $fields the request body; only the whitelisted columns and style_tags are written
     * @return int affected rows
     * @throws ApiError nothing to update (400) or the product does not exist (404)
     */
    public function update(int $id, array $fields): int
    {
        if (array_key_exists('style_tags', $fields)) {
            $fields['style_tags'] = StyleTag::toColumn((array)$fields['style_tags']);
        }

        try {
            $affected = $this->products->updateFields($id, $fields);
        } catch (InvalidArgumentException $error) {
            throw new ApiError($error->getMessage(), 400);
        }

        if ($affected === 0 && !$this->products->exists($id)) {
            throw new ApiError('Product not found', 404);
        }
        return $affected;
    }

    /** @return int affected rows */
    public function delete(int $id): int
    {
        return $this->products->delete($id);
    }

    public function imageOf(int $id): ?string
    {
        return $this->products->imageOf($id);
    }

    /** @return list<array<int|string,mixed>> */
    public function allNewestFirst(): array
    {
        return $this->products->allNewestFirst();
    }

    /**
     * public/admin/edit_product.php: the product row for the raw (already int-cast) id, null when missing.
     *
     * @return array<int|string,mixed>|null
     */
    public function find(int|string $id): ?array
    {
        return $this->products->findById($id);
    }

    /**
     * public/admin/add_product.php: inserts name, price, category and image (the page's own, smaller INSERT).
     *
     * @param mixed $styleTags the posted style_tags list, stored as the column string
     */
    public function createFromAddProductPage(mixed $name, mixed $price, ?int $categoryId, string $image, mixed $styleTags): void
    {
        $this->products->insertFromAddProductPage($name, $price, $categoryId, $image, StyleTag::toColumn((array)$styleTags));
    }

    /**
     * public/admin/edit_product.php: writes every editable column of the product (NULL included).
     *
     * @param mixed $styleTags the posted style_tags list, stored as the column string
     * @return bool what the statement's execute() returned
     */
    public function updateFromEditPage(
        int $id,
        mixed $name,
        mixed $price,
        int $stock,
        mixed $description,
        ?int $categoryId,
        ?int $subcategoryId,
        mixed $image,
        mixed $styleTags
    ): bool {
        return $this->products->updateFromEditPage(
            $id,
            $name,
            $price,
            $stock,
            $description,
            $categoryId,
            $subcategoryId,
            $image,
            StyleTag::toColumn((array)$styleTags)
        );
    }
}
