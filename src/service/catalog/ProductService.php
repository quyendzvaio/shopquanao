<?php


final class ProductService
{
    private CatalogVariantHydrator $catalogVariants;
    private ProductRepository $productRows;
    private SizeRepository $sizeRows;
    private ReviewRepository $reviewRows;
    private CategoryService $categoryList;

    private const SEARCH_CACHE_VERSION = 4;

    public function __construct(private PDO $pdo, private ?int $userId = null, private ?string $rerankerUrl = null)
    {
        $this->catalogVariants = new CatalogVariantHydrator($pdo);
        $this->productRows = new ProductRepository($pdo);
        $this->sizeRows = new SizeRepository($pdo);
        $this->reviewRows = new ReviewRepository($pdo);
        $this->categoryList = new CategoryService($pdo);
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
            $result = (new ProductReranker($this->rerankerUrl))->apply($args['search'] ?? '', $result);
        }
        if (!isset($result['error']) && empty($result['products']) && ($args['in_stock'] ?? null) === true) {
            $result['out_of_stock_matches'] = $this->soldOutMatchCount($args);
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

        $result = ['categories' => $this->categoryList->taxonomy()];
        Cache::setCategories($result);
        return $result;
    }

    /**
     * GET /api/products: hybrid FULLTEXT + LIKE search, filters, sort and optional paging.
     * $query is the request query string; returns the response body.
     *
     * FULLTEXT index ignores words < innodb_ft_min_token_size (default 3).
     * Vietnamese words like "áo" (2 char) use LIKE fallback.
     */
    public function listPage(array $query): array {
        $limit    = isset($query['limit']) ? min(200, max(1, (int)$query['limit'])) : null;
        $page     = $limit ? max(1, (int)($query['page'] ?? 1)) : 1;
        $offset   = $limit ? ($page - 1) * $limit : 0;

        $sort     = $query['sort'] ?? 'newest';
        $sort     = is_string($sort) ? $sort : 'newest'; // a non-string sort always fell through to the default

        $result = $this->productRows->listPage($this->listFilter($query), $sort, $limit, $offset);
        $total = $result['total'];
        $products = $result['rows'];

        // Cast types
        foreach ($products as &$p) {
            $p['id']    = (int)$p['id'];
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)$p['stock'];
            $p['category_id'] = $p['category_id'] ? (int)$p['category_id'] : null;
        }
        unset($p);
        $products = $this->catalogVariants->enrich($products);

        return [
            'products' => $products,
            'pagination' => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'total_pages'=> $limit ? (int)ceil($total / $limit) : 1,
            ],
        ];
    }

    /**
     * GET /api/products/{id}: every product column with taxonomy, its legacy sizes and its variants;
     * null when the product does not exist.
     */
    public function detailWithSizes(int $id): ?array {
        $product = $this->productRows->findWithTaxonomy($id);
        if (!$product) return null;

        $product['id']    = (int)$product['id'];
        $product['price'] = (float)$product['price'];
        $product['stock'] = (int)$product['stock'];
        $product['category_id'] = $product['category_id'] ? (int)$product['category_id'] : null;

        // Get sizes
        $product['sizes'] = $this->sizeRows->sizesForProduct($id);
        $enriched = $this->catalogVariants->enrich([$product]);
        return $enriched[0] ?? $product;
    }

    /** public/index.php: the home page grid (name LIKE, optional category); raw query-string values. */
    public function homeListing(mixed $search, mixed $category): array {
        return $this->productRows->homeListing($search, $category);
    }

    /** public/product.php: the raw products row of the product page, or null when it does not exist. */
    public function pageRow(int|string $id): ?array {
        return $this->productRows->findById($id);
    }

    /** public/product.php: the legacy product_sizes rows of the product page. */
    public function pageSizes(int|string $id): array {
        return $this->sizeRows->sizesForProduct($id);
    }

    /** Parses the list query string into the filter values Products/list.php has always used. */
    private function listFilter(array $query): ProductListFilter {
        $normalizedSearch = CatalogTaxonomy::normalizeSearchArguments([
            'search' => $query['search'] ?? '',
            'category' => $query['category'] ?? '',
            'subcategory' => $query['subcategory'] ?? '',
        ]);
        $search   = $normalizedSearch['search'] ?? '';
        $category = $query['category'] ?? ($normalizedSearch['category_id'] ?? '');
        $categoryKey = $normalizedSearch['category'] ?? '';
        $subcategory = $normalizedSearch['subcategory'] ?? '';
        $color = ProductAttributeNormalizer::normalizeCanonicalColor((string)($query['color'] ?? ''));
        $minPrice = $query['min_price'] ?? '';
        $maxPrice = $query['max_price'] ?? '';
        $inStock  = $query['in_stock'] ?? '';

        $ftsQuery = null;
        $words = [];
        if ($search !== '') {
            $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $search);
            $words = preg_split('/\s+/u', $clean);
            $words = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2));

            // FULLTEXT words (≥ 3 chars, indexed)
            $ftsWords = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3));
            if (!empty($ftsWords)) {
                $ftsQuery = '+' . implode(' +', array_map(function($w) {
                    return str_replace(['\\', "'"], ['\\\\', "\\'"], $w) . '*';
                }, $ftsWords));
            }
        }

        $categoryFilter = null;
        if ($category !== '') {
            $categoryFilter = is_numeric($category) ? (int)$category : (string)$category;
        } elseif ($categoryKey !== '') {
            $categoryFilter = $categoryKey;
        }

        return new ProductListFilter(
            ftsQuery: $ftsQuery,
            likeTerm: $search !== '' ? "%$search%" : null,
            tokens: $words,
            category: $categoryFilter,
            subcategory: $subcategory !== '' ? (string)$subcategory : null,
            minPrice: $minPrice !== '' ? (float)$minPrice : null,
            maxPrice: $maxPrice !== '' ? (float)$maxPrice : null,
            inStock: $inStock === '1',
            color: $color,
        );
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
        $rows = $this->productRows->search($this->searchCriteria($args));
        $products = $rows;

        $search = mb_strtolower(trim((string)($args['search'] ?? '')));
        if ($search !== '') {
            $words = array_values(array_filter(preg_split('/\s+/u', $search) ?: [], fn($w) => mb_strlen($w) >= 2));
            $products = array_values(array_filter($products, function($p) use ($search) {
                return mb_strpos(mb_strtolower($p['name'] ?? ''), $search) !== false;
            }));
            if (empty($products) && count($words) > 1) {
                $allProducts = $rows; // the same query and params: reuse its rows instead of running it twice
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

    /** A "còn hàng" search found nothing: how many products match every other filter (so they are sold out). */
    private function soldOutMatchCount(array $args): int {
        unset($args['in_stock']);
        return count($this->executeSearchProductsDirect($args)['products'] ?? []);
    }

    private function searchCriteria(array $args): ProductSearchCriteria {
        $size = !empty($args['size']) ? ProductAttributeNormalizer::normalizeSize((string)$args['size']) : null;
        $color = !empty($args['color']) ? ProductAttributeNormalizer::normalizeCanonicalColor((string)$args['color']) : null;
        return new ProductSearchCriteria(
            categoryId: !empty($args['category_id']) ? (int)$args['category_id'] : null,
            subcategory: !empty($args['subcategory']) ? (string)$args['subcategory'] : null,
            minPrice: !empty($args['min_price']) ? (float)$args['min_price'] : null,
            maxPrice: !empty($args['max_price']) ? (float)$args['max_price'] : null,
            inStock: ($args['in_stock'] ?? null) === true ? true : null,
            size: $size,
            color: $color,
        );
    }

    private function fetchFreshProductsByIds(array $ids): array {
        $ids = array_values(array_filter(array_unique($ids), fn($id) => $id > 0));
        if ($ids === []) return [];
        $rows = $this->productRows->cardsByIds($ids);
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
        $row = $this->productRows->priceAndStock($id);
        if (!$row) return null;
        return [
            'id' => (int)$row['id'],
            'price' => (float)$row['price'],
            'stock' => (int)($row['stock'] ?? 0),
        ];
    }

    private function fetchProductDetailStatic(int $id): array {
        $product = $this->productRows->detailStatic($id);
        if (!$product) return ['error' => 'Product not found'];

        $product['id'] = (int)$product['id'];
        $product['category_id'] = $product['category_id'] !== null ? (int)$product['category_id'] : null;
        $product['sizes'] = [];
        try {
            $product['sizes'] = $this->sizeRows->sizesForProduct($id);
        } catch (Throwable $e) {}
        $product['available_colors'] = ProductAttributeNormalizer::extractColorsFromProduct($product);

        try {
            $rating = $this->reviewRows->ratingSummary($id);
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

        try {
            $sizesByProduct = [];
            foreach ($this->sizeRows->sizesForProducts($ids) as $row) {
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
