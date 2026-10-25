<?php

/** public/index.php: the category filter bar and the product grid. */
final class HomePage
{
    public function __construct(private ProductService $products, private CategoryService $categories)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $search = $request->query['search'] ?? '';
        $cat_id = $request->query['category'] ?? '';

        return PageResult::view([
            'search' => $search,
            'cat_id' => $cat_id,
            'categories' => $this->categories->homeFilter(),
            'products' => $this->products->homeListing($search, $cat_id),
        ]);
    }
}
