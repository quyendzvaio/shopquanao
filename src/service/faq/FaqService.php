<?php

/** The FAQ list behind GET /api/faq. */
final class FaqService
{
    private const CATEGORIES = ['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size'];

    private FaqRepository $faqs;

    public function __construct(PDO $pdo)
    {
        $this->faqs = new FaqRepository($pdo);
    }

    /**
     * @param string|null $category only the known FAQ categories filter; anything else lists every category
     * @return list<array<int|string,mixed>>
     */
    public function search(?string $category, string $search): array
    {
        $known = $category && in_array($category, self::CATEGORIES) ? $category : null;
        return $this->faqs->search($known, $search);
    }
}
