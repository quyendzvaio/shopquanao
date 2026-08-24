<?php

final class FashionProviderMappingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findSynced(string $provider, int $shopProductId, ?int $shopVariantId = null): ?FashionProviderProductMapping
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fashion_provider_product_mapping
             WHERE provider = ? AND shop_product_id = ? AND mapping_scope = ? AND sync_status = ?
             LIMIT 1'
        );
        $stmt->execute([$provider, $shopProductId, $this->scope($shopVariantId), 'synced']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function savePending(FashionProviderProductMapping $mapping): void
    {
        $params = [
            $mapping->shopProductId,
            $mapping->shopVariantId,
            $this->scope($mapping->shopVariantId),
            $mapping->provider,
            $mapping->providerProductId,
            $mapping->providerVariantId ?? '',
            $mapping->providerColorId ?? '',
            json_encode($mapping->providerIdentifiers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $mapping->syncVersion,
        ];
        if ($this->driver() === 'sqlite') {
            $sql = 'INSERT INTO fashion_provider_product_mapping
                (shop_product_id, shop_variant_id, mapping_scope, provider, provider_product_id, provider_variant_id,
                 provider_color_id, provider_identifiers, sync_status, sync_version, last_synced_at, last_error, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, NULL, NULL, CURRENT_TIMESTAMP)
                ON CONFLICT(provider, shop_product_id, mapping_scope) DO UPDATE SET
                    shop_variant_id = excluded.shop_variant_id,
                    provider_product_id = excluded.provider_product_id,
                    provider_variant_id = excluded.provider_variant_id,
                    provider_color_id = excluded.provider_color_id,
                    provider_identifiers = excluded.provider_identifiers,
                    sync_status = \'pending\', sync_version = excluded.sync_version,
                    last_synced_at = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO fashion_provider_product_mapping
                (shop_product_id, shop_variant_id, mapping_scope, provider, provider_product_id, provider_variant_id,
                 provider_color_id, provider_identifiers, sync_status, sync_version, last_synced_at, last_error)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, NULL, NULL)
                ON DUPLICATE KEY UPDATE provider_product_id = VALUES(provider_product_id),
                    shop_variant_id = VALUES(shop_variant_id), provider_variant_id = VALUES(provider_variant_id),
                    provider_color_id = VALUES(provider_color_id),
                    provider_identifiers = VALUES(provider_identifiers),
                    sync_status = \'pending\', sync_version = VALUES(sync_version),
                    last_synced_at = NULL, last_error = NULL';
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    public function markSynced(string $provider, int $shopProductId, ?int $shopVariantId, string $syncVersion): void
    {
        $now = $this->driver() === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
        $stmt = $this->pdo->prepare(
            "UPDATE fashion_provider_product_mapping SET sync_status = 'synced', sync_version = ?,
             last_synced_at = $now, last_error = NULL WHERE provider = ? AND shop_product_id = ? AND mapping_scope = ?"
        );
        $stmt->execute([$syncVersion, $provider, $shopProductId, $this->scope($shopVariantId)]);
        if ($stmt->rowCount() === 0 && !$this->exists($provider, $shopProductId, $shopVariantId)) {
            throw new RuntimeException('Fashion provider mapping not found');
        }
    }

    public function markFailed(string $provider, int $shopProductId, ?int $shopVariantId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fashion_provider_product_mapping SET sync_status = 'failed', last_error = ?,
             updated_at = CURRENT_TIMESTAMP WHERE provider = ? AND shop_product_id = ? AND mapping_scope = ?"
        );
        $stmt->execute([mb_substr(trim($error), 0, 2000), $provider, $shopProductId, $this->scope($shopVariantId)]);
        if ($stmt->rowCount() === 0 && !$this->exists($provider, $shopProductId, $shopVariantId)) {
            throw new RuntimeException('Fashion provider mapping not found');
        }
    }

    /** @return list<FashionProviderProductMapping> */
    public function findForSync(string $provider, int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM fashion_provider_product_mapping
             WHERE provider = ? AND sync_status IN ('pending', 'failed') ORDER BY updated_at ASC LIMIT $limit"
        );
        $stmt->execute([$provider]);
        return array_map(fn ($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function exists(string $provider, int $shopProductId, ?int $shopVariantId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM fashion_provider_product_mapping WHERE provider = ? AND shop_product_id = ? AND mapping_scope = ?'
        );
        $stmt->execute([$provider, $shopProductId, $this->scope($shopVariantId)]);
        return (bool) $stmt->fetchColumn();
    }

    private function hydrate(array $row): FashionProviderProductMapping
    {
        return new FashionProviderProductMapping(
            (int) $row['shop_product_id'],
            (string) $row['provider'],
            (string) $row['provider_product_id'],
            isset($row['shop_variant_id']) ? (int)$row['shop_variant_id'] : null,
            $this->nullable((string) ($row['provider_variant_id'] ?? '')),
            $this->nullable((string) ($row['provider_color_id'] ?? '')),
            (string) $row['sync_status'],
            $this->nullable((string) ($row['sync_version'] ?? '')),
            $this->nullable((string) ($row['last_synced_at'] ?? '')),
            $this->nullable((string) ($row['last_error'] ?? '')),
            $this->identifiers($row['provider_identifiers'] ?? null)
        );
    }

    private function identifiers(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function scope(?int $shopVariantId): string
    {
        return $shopVariantId === null ? 'product' : 'variant:' . $shopVariantId;
    }
}
