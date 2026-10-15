<?php


final class ProductService
{
    private CatalogVariantHydrator $catalogVariants;

    private const SEARCH_CACHE_VERSION = 4;

    public function __construct(private PDO $pdo, private ?int $userId = null)
    {
        $this->catalogVariants = new CatalogVariantHydrator($pdo);
    }

    public function search(array $args): array {
        $args = $this->normalizeSearchArgs($args);
        $explicitProductId = $this->extractSearchProductId($args);
        if ($explicitProductId !== null) {
            $detail = $this->detail(['product_id' => $explicitProductId]);
            if (isset($detail['product']) && is_array($detail['product'])) {
                $detail['products'] = [$this->productDetailToCard($detail['product'])];
                $detail['pagination'] = [
                    'page' => 1,
                    'limit' => 1,
                    'total' => 1,
                    'total_pages' => 1,
                ];
                $detail['routed_from'] = 'search_products';
                $detail['routed_to'] = 'get_product_detail';
            }
            return $detail;
        }

        $queryParams = [
            'search' => $args['search'] ?? '',
            'sort' => 'price_asc',
            '_v' => self::SEARCH_CACHE_VERSION,
        ];
        foreach (['category_id', 'category', 'subcategory', 'min_price', 'max_price', 'color', 'size', 'in_stock', 'material', 'style', 'occasion', 'avoid', 'semantic_query'] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '') {
                $queryParams[$key] = is_array($args[$key])
                    ? json_encode($args[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $args[$key];
            }
        }

        $cachedIds = Cache::getProductSearchIds($queryParams);
        if ($cachedIds !== null && isset($cachedIds['product_ids']) && is_array($cachedIds['product_ids'])) {
            $freshProducts = $this->fetchFreshProductsByIds(array_map('intval', $cachedIds['product_ids']));
            $freshProducts = array_values(array_filter($freshProducts, fn($p) => ProductAttributeNormalizer::productMatchesConstraints($p, $args)));
            if (!empty($freshProducts)) {
                return [
                    'products' => $freshProducts,
                    'pagination' => [
                        'page' => 1,
                        'limit' => null,
                        'total' => count($freshProducts),
                        'total_pages' => 1,
                    ],
                    'cache' => ['product_cache_hit' => true, 'cache_policy' => 'ids_only_fresh_price_stock'],
                ];
            }
        }

        $result = $this->executeSearchProductsDirect($args);

        if (!isset($result['error']) && !empty($result['products'])) {
            $result = (new ProductReranker())->apply($args['search'] ?? '', $result);
        }

        if (!isset($result['error'])) {
            Cache::setProductSearchIds($queryParams, [
                'product_ids' => array_values(array_map(fn($p) => (int)$p['id'], $result['products'] ?? [])),
                'total' => (int)($result['pagination']['total'] ?? count($result['products'] ?? [])),
            ]);
            $result['cache'] = ['product_cache_hit' => false, 'cache_policy' => 'ids_only_fresh_price_stock'];
        }

        return $result;
    }

    public function detail(array $args): array {
        $id = $args['product_id'] ?? 0;
        if (!$id) return ['error' => 'Product ID required'];

        $static = Cache::getProductDetailStatic($id);
        if ($static === null) {
            $static = $this->fetchProductDetailStatic($id);
            if (isset($static['error'])) {
                return $static;
            }
            Cache::setProductDetailStatic($id, $static);
        }

        $fresh = $this->fetchProductFreshById($id);
        if ($fresh === null) {
            return ['error' => 'Product not found'];
        }

        $product = array_merge($static['product'] ?? [], $fresh);
        $enriched = $this->catalogVariants->enrich([$product]);
        $product = $enriched[0] ?? $product;
        return [
            'product' => $product,
            'cache' => [
                'product_detail_static_cache_hit' => true,
                'cache_policy' => 'static_metadata_only_fresh_price_stock',
            ],
        ];
    }

    public function categories(): array {
        $cached = Cache::getCategories();
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->pdo->query("SELECT id, name, canonical_key, family FROM categories ORDER BY id");
        $result = ['categories' => array_map(
            fn($category) => [
                'id' => (int)$category['id'],
                'name' => (string)$category['name'],
                'canonical_key' => (string)$category['canonical_key'],
                'family' => (string)$category['family'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )];
        Cache::setCategories($result);
        return $result;
    }

    private function normalizeSearchArgs(array $args): array {
        $args = CatalogTaxonomy::normalizeSearchArguments($args);
        $search = mb_strtolower(trim((string)($args['search'] ?? '')));
        if (!empty($args['color'])) {
            $normalizedColor = ProductAttributeNormalizer::normalizeCanonicalColor((string)$args['color']);
            if ($normalizedColor !== null) {
                $args['color'] = $normalizedColor;
            }
        }
        if (!empty($args['size'])) {
            $normalizedSize = ProductAttributeNormalizer::normalizeSize((string)$args['size']);
            if ($normalizedSize !== null) {
                $args['size'] = $normalizedSize;
            }
        }
        if ($search === '') return $args;

        $aliases = [
            'áo bomber' => 'áo khoác bomber',
            'ao bomber' => 'áo khoác bomber',
            'bomber' => 'áo khoác bomber',
            'áo blazer' => 'áo vest',
            'ao blazer' => 'áo vest',
            'quần jean' => 'quần jeans',
            'quan jean' => 'quần jeans',
        ];

        foreach ($aliases as $needle => $normalized) {
            if (mb_strpos($search, $needle) !== false) {
                $args['search'] = $normalized;
                if (empty($args['category_id']) && str_starts_with($normalized, 'áo')) $args['category_id'] = 1;
                if (empty($args['category_id']) && str_starts_with($normalized, 'quần')) $args['category_id'] = 2;
                return $args;
            }
        }

        return $args;
    }

    private function extractSearchProductId(array $args): ?int {
        $search = trim((string)($args['search'] ?? ''));
        if ($search === '') return null;

        if (preg_match('/^(?:mã|ma|id|#|sản phẩm mã|san pham ma|product)?\s*#?\s*(\d+)$/ui', $search, $m)) {
            return max(1, (int)$m[1]);
        }

        return null;
    }

    private function productDetailToCard(array $product): array {
        return [
            'id' => (int)($product['id'] ?? 0),
            'category_id' => isset($product['category_id']) ? (int)$product['category_id'] : null,
            'name' => (string)($product['name'] ?? ''),
            'price' => (float)($product['price'] ?? 0),
            'stock' => (int)($product['stock'] ?? 0),
            'image' => (string)($product['image'] ?? ''),
            'category_name' => (string)($product['category_name'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'sizes' => $product['sizes'] ?? [],
            'available_sizes' => $product['available_sizes'] ?? ProductAttributeNormalizer::productSizes($product),
            'available_colors' => $product['available_colors'] ?? ProductAttributeNormalizer::extractColorsFromProduct($product),
            'canonical_colors' => $product['canonical_colors'] ?? [],
            'colors' => $product['colors'] ?? [],
            'variants' => $product['variants'] ?? [],
            'subcategory' => $product['subcategory'] ?? null,
            'subcategory_name' => $product['subcategory_name'] ?? null,
        ];
    }

    private function executeSearchProductsDirect(array $args): array {
        $args = $this->normalizeSearchArgs($args);
        $sql = "SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock, p.image, p.description,
                       c.name as category_name, c.canonical_key as category,
                       sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE 1=1";
        $params = [];

        if (!empty($args['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = (int)$args['category_id'];
        }
        if (!empty($args['subcategory'])) {
            $sql .= " AND sc.canonical_key = ?";
            $params[] = (string)$args['subcategory'];
        }
        if (!empty($args['min_price'])) {
            $sql .= " AND p.price >= ?";
            $params[] = (float)$args['min_price'];
        }
        if (!empty($args['max_price'])) {
            $sql .= " AND p.price <= ?";
            $params[] = (float)$args['max_price'];
        }
        if (($args['in_stock'] ?? null) === true) {
            $sql .= " AND (p.stock > 0 OR EXISTS (
                SELECT 1 FROM product_variants av
                WHERE av.product_id = p.id AND av.is_active = 1 AND COALESCE(av.stock, p.stock) > 0
            ))";
        }
        if (!empty($args['size'])) {
            $size = ProductAttributeNormalizer::normalizeSize((string)$args['size']);
            if ($size !== null) {
                $sql .= " AND (EXISTS (
                    SELECT 1 FROM product_variants sv
                    WHERE sv.product_id = p.id AND sv.is_active = 1 AND UPPER(sv.size) = ?
                ) OR EXISTS (
                    SELECT 1 FROM product_sizes ps WHERE ps.product_id = p.id AND UPPER(ps.size_name) = ?
                ))";
                $params[] = $size;
                $params[] = $size;
            }
        }
        if (!empty($args['color'])) {
            $canonicalColor = ProductAttributeNormalizer::normalizeCanonicalColor((string)$args['color']);
            if ($canonicalColor !== null) {
                $sql .= " AND (EXISTS (
                    SELECT 1 FROM product_variants cv
                    JOIN colors cc ON cc.id = cv.color_id
                    WHERE cv.product_id = p.id AND cv.is_active = 1 AND cc.canonical_key = ?
                ) OR NOT EXISTS (
                    SELECT 1 FROM product_variants nv WHERE nv.product_id = p.id AND nv.is_active = 1
                ))";
                $params[] = $canonicalColor;
            }
        }

        $sql .= " ORDER BY p.price ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $search = mb_strtolower(trim((string)($args['search'] ?? '')));
        if ($search !== '') {
            $words = array_values(array_filter(preg_split('/\s+/u', $search) ?: [], fn($w) => mb_strlen($w) >= 2));
            $products = array_values(array_filter($products, function($p) use ($search) {
                return mb_strpos(mb_strtolower($p['name'] ?? ''), $search) !== false;
            }));
            if (empty($products) && count($words) > 1) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $products = array_values(array_filter($allProducts, function($p) use ($words) {
                    $name = mb_strtolower($p['name'] ?? '');
                    foreach ($words as $word) {
                        if (mb_strpos($name, $word) === false) return false;
                    }
                    return true;
                }));
            }
        }

        $this->attachProductSizes($products);
        $products = $this->catalogVariants->enrich($products);
        $products = array_values(array_filter($products, fn($p) => ProductAttributeNormalizer::productMatchesConstraints($p, $args)));

        foreach ($products as &$p) {
            $p['id'] = (int)$p['id'];
            $p['category_id'] = $p['category_id'] !== null ? (int)$p['category_id'] : null;
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)($p['stock'] ?? 0);
        }
        unset($p);

        return [
            'products' => $products,
            'pagination' => [
                'page' => 1,
                'limit' => null,
                'total' => count($products),
                'total_pages' => 1,
            ],
        ];
    }

    private function fetchFreshProductsByIds(array $ids): array {
        $ids = array_values(array_filter(array_unique($ids), fn($id) => $id > 0));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.subcategory_id, p.name, p.price, p.stock,
                    p.image, p.description, c.name as category_name, c.canonical_key as category,
                    sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE p.id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $p) {
            $p['id'] = (int)$p['id'];
            $p['category_id'] = $p['category_id'] !== null ? (int)$p['category_id'] : null;
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)($p['stock'] ?? 0);
            $byId[$p['id']] = $p;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) $ordered[] = $byId[$id];
        }
        $this->attachProductSizes($ordered);
        return $this->catalogVariants->enrich($ordered);
    }

    private function fetchProductFreshById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT id, price, stock FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (int)$row['id'],
            'price' => (float)$row['price'],
            'stock' => (int)($row['stock'] ?? 0),
        ];
    }

    private function fetchProductDetailStatic(int $id): array {
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.subcategory_id, p.name, p.description, p.image,
                    c.name as category_name, c.canonical_key as category,
                    sc.canonical_key as subcategory, sc.display_name as subcategory_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_subcategories sc ON p.subcategory_id = sc.id
                WHERE p.id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return ['error' => 'Product not found'];

        $product['id'] = (int)$product['id'];
        $product['category_id'] = $product['category_id'] !== null ? (int)$product['category_id'] : null;
        $product['sizes'] = [];
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
            $stmt->execute([$id]);
            $product['sizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        $product['available_colors'] = ProductAttributeNormalizer::extractColorsFromProduct($product);

        try {
            $stmt = $this->pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
            $stmt->execute([$id]);
            $rating = $stmt->fetch(PDO::FETCH_ASSOC);
            $product['avg_rating'] = $rating && $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null;
            $product['total_reviews'] = $rating ? (int)$rating['total_reviews'] : 0;
        } catch (Throwable $e) {
            $product['avg_rating'] = null;
            $product['total_reviews'] = 0;
        }

        return ['product' => $product];
    }

    private function attachProductSizes(array &$products): void {
        $ids = array_values(array_filter(array_map(fn($p) => (int)($p['id'] ?? 0), $products), fn($id) => $id > 0));
        if ($ids === []) return;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT product_id, size_name FROM product_sizes WHERE product_id IN ($placeholders)");
            $stmt->execute($ids);
            $sizesByProduct = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $productId = (int)($row['product_id'] ?? 0);
                $size = ProductAttributeNormalizer::normalizeSize((string)($row['size_name'] ?? ''));
                if ($productId > 0 && $size !== null) {
                    $sizesByProduct[$productId][] = ['size_name' => $size];
                }
            }
            foreach ($products as &$product) {
                $id = (int)($product['id'] ?? 0);
                $product['sizes'] = array_values(array_unique($sizesByProduct[$id] ?? [], SORT_REGULAR));
            }
            unset($product);
        } catch (Throwable $e) {
            foreach ($products as &$product) {
                $product['sizes'] = $product['sizes'] ?? [];
            }
            unset($product);
        }
    }

    private function absoluteUrl(string $path): string {
        if (function_exists('getBaseUrl')) return rtrim(getBaseUrl(), '/') . $path;
        return $path;
    }

    private function isSqlite(): bool {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function fetchJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return ['error' => "HTTP request failed: $httpCode"];
        }

        $data = json_decode($raw, true);
        return $data ?: ['error' => 'Invalid JSON response'];
    }
}
