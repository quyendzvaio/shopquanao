<?php

/** Product reviews: the list with reviewer names, the rating summary and adding a review. */
final class ReviewService
{
    private ReviewRepository $reviews;
    private ProductRepository $products;

    public function __construct(PDO $pdo)
    {
        $this->reviews = new ReviewRepository($pdo);
        $this->products = new ProductRepository($pdo);
    }

    /** @return list<array<int|string,mixed>> reviews of the product with `username`, newest first */
    public function forProductWithUsername(int $productId): array
    {
        return $this->reviews->forProductWithUsername($productId);
    }

    /** @return array{avg_rating:?float,total_reviews:int} the average (one decimal, null when unrated) and the count */
    public function ratingSummary(int $productId): array
    {
        $rating = $this->reviews->ratingSummary($productId);
        return [
            'avg_rating'    => $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null,
            'total_reviews' => (int)$rating['total_reviews'],
        ];
    }

    /** @throws ApiError the product does not exist (404) */
    public function add(int $productId, int $userId, int $rating, string $comment): void
    {
        if (!$this->products->exists($productId)) {
            throw new ApiError('Product not found', 404);
        }
        $this->reviews->add($productId, $userId, $rating, $comment);
    }

    /**
     * public/submit_review.php: adds the review from the form, values bound raw. Unlike add() it does not check
     * that the product exists: a bad id surfaces as the database error the page always reported.
     */
    public function addFromReviewForm(mixed $productId, int|string $userId, mixed $rating, string $comment): void
    {
        $this->reviews->addFromReviewForm($productId, $userId, $rating, $comment);
    }
}
