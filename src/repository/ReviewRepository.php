<?php

/** Reads and writes the reviews table. */
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

    /** @return list<array<int|string,mixed>> the reviews of one product with the reviewer's username, newest first (PDO default fetch mode) */
    public function forProductWithUsername(int $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * public/product.php: the reviews list of the product page.
     * Known bug kept for byte identity (Part B plan D4); fix in follow-up.
     * The join compares r.id with u.id instead of r.user_id with u.id.
     *
     * @return list<array<int|string,mixed>> (PDO default fetch mode)
     */
    public function forProductLegacyJoin(int $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public function add(int $productId, int $userId, int $rating, string $comment): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productId, $userId, $rating, $comment]);
    }
}
