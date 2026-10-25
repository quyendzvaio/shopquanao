<?php

/** Reads the products table, joined with its category and subcategory where a caller needs them. */
final class ProductRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int|string,mixed>|null the raw products row (PDO default fetch mode) */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * public/index.php: the home page grid, a name LIKE filter plus an optional category.
     * $search and $category are the raw query-string values, bound exactly as the page always did.
     *
     * @return list<array<int|string,mixed>> every products column (PDO default fetch mode)
     */
    public function homeListing(mixed $search, mixed $category): array
    {
        $sql = "SELECT * FROM products WHERE name LIKE ?";
        $params = ["%$search%"];

        if ($category) {
            $sql .= " AND category_id = ?";
            $params[] = $category;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null every product column plus category/subcategory keys and names */
    public function findWithTaxonomy(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT p.*, c.name as category_name, c.canonical_key as category,
        sc.canonical_key as subcategory, sc.display_name as subcategory_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
    WHERE p.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null the cacheable detail columns (no price or stock) */
    public function detailStatic(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.subcategory_id, p.name, p.description, p.image,
                    c.name as category_name, c.canonical_key as category,
                    sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE p.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$id]);
        return (bool)$stmt->fetch();
    }

    public function count(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    }

    /** @return list<array<int|string,mixed>> every product, newest first (PDO default fetch mode) */
    public function allNewestFirst(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM products ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    /** The image file name of one product; null when the product does not exist. */
    public function imageOf(int $id): ?string
    {
        $stmt = $this->pdo->prepare("SELECT image FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $image = $stmt->fetchColumn();
        return $image === false || $image === null ? null : (string)$image;
    }

    /**
     * @param array{name:mixed,price:mixed,stock:mixed,description:mixed,category_id:mixed,subcategory_id:mixed,image:mixed,style_tags:mixed} $row
     * @return int the new product id
     */
    public function insert(array $row): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO products
    (name, price, stock, description, category_id, subcategory_id, image, style_tags)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$row['name'], $row['price'], $row['stock'], $row['description'], $row['category_id'],
            $row['subcategory_id'], $row['image'], $row['style_tags']]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Partial update: only the whitelisted columns present in $fields are written, `style_tags` (already a
     * column string) whenever the key exists.
     *
     * @param array<string,mixed> $fields
     * @return int affected rows
     * @throws InvalidArgumentException when no writable field is present
     */
    public function updateFields(int $id, array $fields): int
    {
        $set = [];
        $params = [];

        foreach (['name', 'price', 'stock', 'description', 'category_id', 'subcategory_id', 'image'] as $f) {
            if (isset($fields[$f])) {
                $set[] = "$f = ?";
                $params[] = $fields[$f];
            }
        }

        if (array_key_exists('style_tags', $fields)) {
            $set[] = "style_tags = ?";
            $params[] = $fields['style_tags'];
        }

        if (!$set) {
            throw new InvalidArgumentException('No fields to update');
        }

        $params[] = $id;
        $sql = "UPDATE products SET " . implode(', ', $set) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @return int affected rows */
    public function delete(int $id): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /** @return array{id:mixed,price:mixed,stock:mixed}|null the fresh, uncached fields */
    public function priceAndStock(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, price, stock FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{id:mixed,stock:mixed}|null the cart's view of a product */
    public function idAndStock(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, stock FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The stock column of one product; false when the product does not exist (PDOStatement::fetchColumn). */
    public function stockById(int $id): mixed
    {
        return $this->pdo->query('SELECT stock FROM products WHERE id = ' . $id)->fetchColumn();
    }

    /** Takes $qty units out of stock only if that many remain; true when exactly the one row changed. */
    public function decrementStock(int $id, int $qty): bool
    {
        $stmt = $this->pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $stmt->execute([$qty, $id, $qty]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $ids non-empty
     * @return list<array<string,mixed>> product cards in database order
     */
    public function cardsByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock,
                    p.image, p.description, c.name as category_name, c.canonical_key as category,
                    sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE p.id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> agent search candidates, cheapest first (name matching is done by the caller) */
    public function search(ProductSearchCriteria $c): array
    {
        $sql = "SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock, p.image, p.description,
                       c.name as category_name, c.canonical_key as category,
                       sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE 1=1";
        $params = [];

        if ($c->categoryId !== null) {
            $sql .= " AND p.category_id = ?";
            $params[] = $c->categoryId;
        }
        if ($c->subcategory !== null) {
            $sql .= " AND sc.canonical_key = ?";
            $params[] = $c->subcategory;
        }
        if ($c->minPrice !== null) {
            $sql .= " AND p.price >= ?";
            $params[] = $c->minPrice;
        }
        if ($c->maxPrice !== null) {
            $sql .= " AND p.price <= ?";
            $params[] = $c->maxPrice;
        }
        if ($c->inStock === true) {
            $sql .= " AND (p.stock > 0 OR EXISTS (
                SELECT 1 FROM product_variants av
                WHERE av.product_id = p.id AND av.is_active = 1 AND COALESCE(av.stock, p.stock) > 0
            ))";
        }
        if ($c->size !== null) {
            $sql .= " AND (EXISTS (
                    SELECT 1 FROM product_variants sv
                    WHERE sv.product_id = p.id AND sv.is_active = 1 AND UPPER(sv.size) = ?
                ) OR EXISTS (
                    SELECT 1 FROM product_sizes ps WHERE ps.product_id = p.id AND UPPER(ps.size_name) = ?
                ))";
            $params[] = $c->size;
            $params[] = $c->size;
        }
        if ($c->color !== null) {
            $sql .= " AND (EXISTS (
                    SELECT 1 FROM product_variants cv
                    JOIN colors cc ON cc.id = cv.color_id
                    WHERE cv.product_id = p.id AND cv.is_active = 1 AND cc.canonical_key = ?
                ) OR NOT EXISTS (
                    SELECT 1 FROM product_variants nv WHERE nv.product_id = p.id AND nv.is_active = 1
                ))";
            $params[] = $c->color;
        }

        $sql .= " ORDER BY p.price ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One page of the REST product list and the total row count of the filter.
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function listPage(ProductListFilter $f, string $sort, ?int $limit, int $offset): array
    {
        [$whereClause, $params] = $this->listWhere($f);
        $total = $this->listCount($whereClause, $params);
        return ['rows' => $this->listRows($whereClause, $params, $sort, $limit, $offset), 'total' => $total];
    }

    /** @return list<array<string,mixed>> id, name and description of every product, by id */
    public function allForBackfill(): array
    {
        return $this->pdo->query(
            'SELECT id, name, description FROM products ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0: string, 1: list<mixed>} the WHERE clause (or '') and its positional params */
    private function listWhere(ProductListFilter $f): array
    {
        $where  = [];
        $params = [];

        // ---- Hybrid search: FULLTEXT (fast) + LIKE (fallback for short words) ----
        if ($f->likeTerm !== null) {
            $searchParts = [];

            if ($f->ftsQuery !== null) {
                // Use FULLTEXT in BOOLEAN MODE for the long words
                $searchParts[] = "MATCH(p.name) AGAINST(? IN BOOLEAN MODE)";
                $params[] = $f->ftsQuery;
            }

            // Full phrase match catches exact product type queries.
            $searchParts[] = "p.name LIKE ?";
            $params[] = $f->likeTerm;

            // Token AND match catches natural queries like "áo bomber" vs "Áo Khoác Bomber".
            if (count($f->tokens) > 1) {
                $tokenParts = [];
                foreach ($f->tokens as $word) {
                    $tokenParts[] = "p.name LIKE ?";
                    $params[] = "%$word%";
                }
                $searchParts[] = '(' . implode(' AND ', $tokenParts) . ')';
            }

            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }

        if (is_int($f->category)) {
            $where[] = "p.category_id = ?";
            $params[] = $f->category;
        } elseif ($f->category !== null) {
            $where[] = "c.canonical_key = ?";
            $params[] = $f->category;
        }
        if ($f->subcategory !== null) {
            $where[] = "sc.canonical_key = ?";
            $params[] = $f->subcategory;
        }
        if ($f->minPrice !== null) {
            $where[] = "p.price >= ?";
            $params[] = $f->minPrice;
        }
        if ($f->maxPrice !== null) {
            $where[] = "p.price <= ?";
            $params[] = $f->maxPrice;
        }
        if ($f->inStock) {
            $where[] = "(p.stock > 0 OR EXISTS (
        SELECT 1 FROM product_variants av
        WHERE av.product_id = p.id AND av.is_active = 1 AND COALESCE(av.stock, p.stock) > 0
    ))";
        }
        if ($f->color !== null) {
            $where[] = "EXISTS (
        SELECT 1 FROM product_variants cv
        JOIN colors cc ON cc.id = cv.color_id
        WHERE cv.product_id = p.id AND cv.is_active = 1 AND cc.canonical_key = ?
    )";
            $params[] = $f->color;
        }

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        return [$whereClause, $params];
    }

    /** @param list<mixed> $params */
    private function listCount(string $whereClause, array $params): int
    {
        $sqlCount = "SELECT COUNT(*) FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id" . $whereClause;
        $stmt = $this->pdo->prepare($sqlCount);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function listRows(string $whereClause, array $params, string $sort, ?int $limit, int $offset): array
    {
        // Sort — uses composite idx_category_price when category + price filters present
        switch ($sort) {
            case 'price_asc':  $orderBy = ' ORDER BY p.price ASC'; break;
            case 'price_desc': $orderBy = ' ORDER BY p.price DESC'; break;
            case 'name_asc':   $orderBy = ' ORDER BY p.name ASC'; break;
            default:           $orderBy = ' ORDER BY p.id DESC'; break;
        }

        // Data query — SELECT only needed columns (omit description for list perf)
        $sqlData = "SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock, p.image,
                   c.name as category_name, c.canonical_key as category,
                   sc.canonical_key as subcategory, sc.display_name as subcategory_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id"
            . " LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id"
            . $whereClause . $orderBy;

        if ($limit !== null) {
            $sqlData .= " LIMIT $limit OFFSET $offset";
        }

        $stmt = $this->pdo->prepare($sqlData);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
