<?php

/** public/product.php: one product with its sizes and reviews, or the not-found message. */
final class ProductPage
{
    public function __construct(private ProductService $products, private ReviewService $reviews)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $product_id = $request->query['id'] ?? 0;
        $product = is_scalar($product_id) ? $this->products->pageRow((int) $product_id) : null;

        if (!$product) {
            return PageResult::view(['notFound' => true]);
        }

        return PageResult::view([
            'notFound' => false,
            'product_id' => $product_id,
            'product' => $product,
            'sizes' => $this->products->pageSizes((int) $product_id),
            'reviews' => $this->reviews->forProductLegacyJoin((int) $product_id),
            'loggedIn' => isset($session['user_id']),
        ]);
    }
}
