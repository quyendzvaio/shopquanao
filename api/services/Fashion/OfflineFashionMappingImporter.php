<?php

/**
 * Imports mappings produced by FindMine onboarding/catalog transfer.
 * This is intentionally offline and must never be called from a chat request.
 */
final class OfflineFashionMappingImporter
{
    public function __construct(
        private PDO $pdo,
        private FashionProviderMappingRepository $repository
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @return array{processed: int, synced: int, failed: int, failures: list<array{row: int, shop_product_id: int, error: string}>}
     */
    public function import(iterable $rows, string $provider, string $syncVersion, bool $dryRun = false): array
    {
        $provider = trim($provider);
        $syncVersion = trim($syncVersion);
        if ($provider === '' || $syncVersion === '') {
            throw new InvalidArgumentException('provider and syncVersion are required');
        }

        $report = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'conflicts' => 0, 'synced' => 0, 'failed' => 0, 'dry_run' => $dryRun, 'failures' => []];
        foreach ($rows as $index => $row) {
            $report['processed']++;
            $shopProductId = (int) ($row['shop_product_id'] ?? 0);
            try {
                if (!$this->shopProductExists($shopProductId)) {
                    throw new InvalidArgumentException('Shop product does not exist');
                }
                $shopVariantId = $this->nullableInt($row['shop_variant_id'] ?? null);
                if ($shopVariantId !== null && !$this->shopVariantBelongsToProduct($shopVariantId, $shopProductId)) {
                    throw new InvalidArgumentException('Shop variant does not belong to shop product');
                }
                $mapping = new FashionProviderProductMapping(
                    $shopProductId,
                    $provider,
                    trim((string) ($row['provider_product_id'] ?? '')),
                    $shopVariantId,
                    $this->nullable($row['provider_variant_id'] ?? null),
                    $this->nullable($row['provider_color_id'] ?? null),
                    'pending',
                    $syncVersion,
                    null,
                    null,
                    $this->identifiers($row['provider_identifiers_json'] ?? null)
                );
                $exists = $this->mappingExists($provider, $shopProductId, $shopVariantId);

                if ($dryRun) {
                    $report['synced']++;
                    $report[$exists ? 'updated' : 'inserted']++;
                    continue;
                }
                $this->pdo->beginTransaction();
                $this->repository->savePending($mapping);
                // A row in this file is an operator assertion that the provider
                // catalog entry was provisioned outside the chat runtime.
                $this->repository->markSynced($provider, $shopProductId, $mapping->shopVariantId, $syncVersion);
                $this->pdo->commit();
                $report['synced']++;
                $report[$exists ? 'updated' : 'inserted']++;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $report['failed']++;
                $isConflict = $error instanceof PDOException && preg_match('/unique|duplicate|constraint/i', $error->getMessage());
                $report[$isConflict ? 'conflicts' : 'invalid']++;
                $report['failures'][] = [
                    'row' => (int) $index + 2,
                    'shop_product_id' => $shopProductId,
                    'error' => $error->getMessage(),
                ];
                error_log(sprintf(
                    'Fashion mapping import failed at row %d for product %d: %s',
                    (int) $index + 2,
                    $shopProductId,
                    $error->getMessage()
                ));
            }
        }
        return $report;
    }

    private function mappingExists(string $provider, int $productId, ?int $variantId): bool
    {
        $scope = $variantId === null ? 'product' : 'variant:' . $variantId;
        $stmt = $this->pdo->prepare('SELECT 1 FROM fashion_provider_product_mapping WHERE provider=? AND shop_product_id=? AND mapping_scope=?');
        $stmt->execute([$provider, $productId, $scope]);
        return (bool) $stmt->fetchColumn();
    }

    private function shopProductExists(int $shopProductId): bool
    {
        if ($shopProductId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE id = ?');
        $stmt->execute([$shopProductId]);
        return (bool) $stmt->fetchColumn();
    }

    private function shopVariantBelongsToProduct(int $variantId, int $productId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?');
        $stmt->execute([$variantId, $productId]);
        return (bool) $stmt->fetchColumn();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        $id = (int)$value;
        if ($id <= 0) throw new InvalidArgumentException('shop_variant_id must be a positive integer');
        return $id;
    }

    private function identifiers(mixed $value): array
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return [];
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new InvalidArgumentException('provider_identifiers_json must be a JSON object');
        return $decoded;
    }
}
