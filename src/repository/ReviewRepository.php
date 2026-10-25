<?php

/** Reads the reviews table. */
final class ReviewRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{avg_rating:mixed,total_reviews:mixed}|null */
    public function ratingSummary(int $productId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
