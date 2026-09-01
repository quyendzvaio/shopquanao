<?php

/** Grounds provider-neutral references against the existing private Product Search. */
final class PrivateCatalogStyleMapper
{
    public function __construct(
        private ParallelComplementaryProductSearcher $searcher,
        private FashionTaxonomyNormalizer $taxonomy = new FashionTaxonomyNormalizer()
    ) {}

    public function map(StyleReference $reference, ?array $anchorProduct = null): MappedStyleReference
    {
        $requirement = $this->requirement($reference);
        if ($requirement === null) {
            return new MappedStyleReference($reference, [], null, 0.0, 'rejected', [
                'reason' => 'unsupported_reference_category',
                'hard_filter' => true,
            ]);
        }

        $groups = $this->searcher->search([$requirement]);
        return $this->mapGroup($reference, $groups[0] ?? [], $anchorProduct);
    }

    /** @param array<string,mixed> $group */
    private function mapGroup(StyleReference $reference, array $group, ?array $anchorProduct): MappedStyleReference
    {
        $rawCandidates = [];
        foreach (($group['products'] ?? []) as $product) {
            if (!is_array($product)) continue;
            $candidate = $this->privateProduct($product);
            if ($candidate === null) continue;
            $rawCandidates[] = $candidate;
        }

        $accepted = [];
        $rejected = [];
        foreach ($rawCandidates as $candidate) {
            if (!$this->available($candidate)) {
                $rejected[] = ['id' => (int) $candidate['id'], 'reason' => 'unavailable'];
                continue;
            }
            if (!$this->roleCompatible($reference->role, $candidate)) {
                $rejected[] = ['id' => (int) $candidate['id'], 'reason' => 'role_mismatch'];
                continue;
            }
            if (!$this->categoryCompatible($reference, $candidate)) {
                $rejected[] = ['id' => (int) $candidate['id'], 'reason' => 'category_mismatch'];
                continue;
            }
            if ($this->hasConflictingSpecificSubtype($reference, $candidate)) {
                $rejected[] = ['id' => (int) $candidate['id'], 'reason' => 'no_confident_mapping'];
                continue;
            }
            $candidate['_mapping_score'] = $this->score($reference, $candidate, $anchorProduct);
            $accepted[] = $candidate;
        }

        usort($accepted, static fn (array $left, array $right): int =>
            ($right['_mapping_score'] <=> $left['_mapping_score'])
            ?: ((int) $left['id'] <=> (int) $right['id'])
        );
        $selected = $accepted[0] ?? null;
        $score = $selected !== null ? (float) ($selected['_mapping_score'] ?? 0.0) : 0.0;
        foreach ($accepted as &$candidate) unset($candidate['_mapping_score']);
        unset($candidate);
        if ($selected !== null) unset($selected['_mapping_score']);

        return new MappedStyleReference(
            $reference,
            array_values($accepted),
            $selected,
            $score,
            $selected === null ? 'no_match' : 'mapped',
            [
                'hard_filters' => ['available', 'role', 'category'],
                'retrieved_count' => count($rawCandidates),
                'accepted_count' => count($accepted),
                'rejected' => $rejected,
                'anchor_product_id' => $anchorProduct !== null ? (int) ($anchorProduct['id'] ?? 0) : null,
            ]
        );
    }

    /** @return list<MappedStyleReference> */
    public function mapMany(array $references, ?array $anchorProduct = null): array
    {
        $mapped = [];
        $supported = [];
        $requirements = [];
        foreach ($references as $position => $reference) {
            if (!$reference instanceof StyleReference) throw new InvalidArgumentException('Expected StyleReference values');
            $requirement = $this->requirement($reference);
            if ($requirement === null) {
                $mapped[$position] = new MappedStyleReference($reference, [], null, 0.0, 'rejected', [
                    'reason' => 'unsupported_reference_category', 'hard_filter' => true,
                ]);
                continue;
            }
            $supported[] = ['position' => $position, 'reference' => $reference];
            $requirements[] = $requirement;
        }
        if ($requirements === []) return $mapped;

        $groups = $this->searcher->search($requirements);
        foreach ($supported as $index => $entry) {
            $mapped[$entry['position']] = $this->mapGroup($entry['reference'], $groups[$index] ?? [], $anchorProduct);
        }
        ksort($mapped);
        return array_values($mapped);
    }

    private function requirement(StyleReference $reference): ?FashionRequirement
    {
        $normalized = $this->taxonomy->normalize(new ComplementaryItemRequirement(
            $reference->category ?? $reference->role,
            $reference->styleTags,
            $reference->colors,
            $reference->materials,
            1,
            $reference->subcategory
        ));
        if ($normalized === null) return null;
        return new FashionRequirement(
            1,
            $reference->category ?? $reference->role,
            $normalized->search,
            $normalized->categoryId,
            $normalized->styles,
            $normalized->colors,
            $normalized->materials,
            $normalized->canonicalCategory,
            $normalized->subcategory
        );
    }

    /** @return array<string,mixed>|null */
    private function privateProduct(array $product): ?array
    {
        $id = (int) ($product['id'] ?? 0);
        if ($id <= 0) return null;
        unset($product['provider_product_id'], $product['provider_variant_id'], $product['provider_color_id']);
        return $product;
    }

    private function available(array $product): bool
    {
        if (array_key_exists('is_visible', $product) && !$product['is_visible']) return false;
        if (array_key_exists('active', $product) && !$product['active']) return false;
        if ((int) ($product['stock'] ?? 0) > 0) return true;
        foreach (($product['variants'] ?? []) as $variant) {
            if (is_array($variant) && !empty($variant['is_active']) && (int) ($variant['stock'] ?? 0) > 0) return true;
        }
        return false;
    }

    private function roleCompatible(string $role, array $product): bool
    {
        $role = ProductAttributeNormalizer::normalizeText($role);
        $category = ProductAttributeNormalizer::normalizeText((string) ($product['category'] ?? $product['category_name'] ?? ''));
        $subcategory = ProductAttributeNormalizer::normalizeText((string) ($product['subcategory'] ?? $product['subcategory_name'] ?? ''));
        $text = trim($category . ' ' . $subcategory . ' ' . ProductAttributeNormalizer::normalizeText((string) ($product['name'] ?? '')));
        return match ($role) {
            'shoe', 'shoes', 'footwear' => $this->containsAny($text, ['footwear', 'shoe', 'sneaker', 'loafer', 'boot', 'sandal', 'giay']),
            'bottom', 'bottoms' => $this->containsAny($text, ['bottom', 'trouser', 'pant', 'jean', 'chino', 'short', 'skirt', 'quan', 'vay']),
            'outerwear', 'layer' => $this->containsAny($text, ['outerwear', 'jacket', 'coat', 'blazer', 'cardigan', 'vest', 'ao khoac']),
            'top', 'tops' => $this->containsAny($text, ['top', 'shirt', 'tee', 'blouse', 'sweater', 'hoodie', 'polo', 'ao']),
            'accessory', 'accessories' => $this->containsAny($text, ['accessor', 'bag', 'belt', 'hat', 'watch', 'sunglass', 'tui', 'that lung']),
            default => false,
        };
    }

    private function categoryCompatible(StyleReference $reference, array $product): bool
    {
        $category = ProductAttributeNormalizer::normalizeText((string) ($product['category'] ?? $product['category_name'] ?? ''));
        $role = ProductAttributeNormalizer::normalizeText($reference->role);
        if ($role === 'shoe' || $role === 'footwear') return $category === 'footwear' || $this->containsAny($category, ['shoe', 'footwear']);
        if ($role === 'bottom' || $role === 'bottoms') return $this->containsAny($category, ['bottom', 'trouser', 'pant', 'skirt', 'quan']);
        if ($role === 'outerwear' || $role === 'layer') return $this->containsAny($category, ['outerwear', 'jacket', 'coat', 'blazer', 'ao khoac']);
        if ($role === 'top' || $role === 'tops') return $this->containsAny($category, ['top', 'shirt', 'ao']);
        if ($role === 'accessory' || $role === 'accessories') return $this->containsAny($category, ['accessor', 'bag', 'belt', 'tui', 'that']);
        return false;
    }

    private function hasConflictingSpecificSubtype(StyleReference $reference, array $product): bool
    {
        $role = ProductAttributeNormalizer::normalizeText($reference->role);
        if (!in_array($role, ['shoe', 'shoes', 'footwear'], true)) return false;

        $referenceSubtype = $this->footwearSubtype(implode(' ', [
            (string) ($reference->subcategory ?? ''),
            $reference->referenceText,
        ]));
        $candidateSubtype = $this->footwearSubtype(implode(' ', [
            (string) ($product['subcategory'] ?? $product['subcategory_name'] ?? ''),
            (string) ($product['name'] ?? ''),
        ]));
        return $referenceSubtype !== null
            && $candidateSubtype !== null
            && $referenceSubtype !== $candidateSubtype;
    }

    private function footwearSubtype(string $text): ?string
    {
        $text = ProductAttributeNormalizer::normalizeText($text);
        return match (true) {
            $this->containsAny($text, ['loafer', 'moccasin']) => 'loafer',
            $this->containsAny($text, ['sneaker', 'trainer']) => 'sneaker',
            $this->containsAny($text, ['oxford', 'derby', 'brogue', 'dress shoe']) => 'dress_shoe',
            $this->containsAny($text, ['boot']) => 'boot',
            $this->containsAny($text, ['sandal', 'slide']) => 'sandal',
            default => null,
        };
    }

    private function score(StyleReference $reference, array $product, ?array $anchor): float
    {
        $text = ProductAttributeNormalizer::normalizeText(implode(' ', [
            (string) ($product['name'] ?? ''),
            (string) ($product['category_name'] ?? ''),
            (string) ($product['subcategory_name'] ?? $product['subcategory'] ?? ''),
            json_encode($product['canonical_colors'] ?? $product['colors'] ?? []),
        ]));
        $score = 0.45;
        if ($this->containsAny($text, array_map([ProductAttributeNormalizer::class, 'normalizeText'], $reference->colors))) $score += 0.2;
        if ($this->containsAny($text, array_map([ProductAttributeNormalizer::class, 'normalizeText'], $reference->styleTags))) $score += 0.15;
        if ($reference->subcategory !== null && str_contains($text, ProductAttributeNormalizer::normalizeText($reference->subcategory))) $score += 0.1;
        if ($anchor !== null && ProductAttributeNormalizer::normalizeText((string) ($anchor['category'] ?? '')) === ProductAttributeNormalizer::normalizeText((string) ($product['category'] ?? ''))) $score += 0.05;
        return min(1.0, $score);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = ProductAttributeNormalizer::normalizeText((string) $needle);
            if ($needle !== '' && str_contains($haystack, $needle)) return true;
        }
        return false;
    }
}
