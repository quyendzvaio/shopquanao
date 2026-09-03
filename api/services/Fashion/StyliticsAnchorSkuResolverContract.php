<?php

/** Resolves a private anchor to the SKU Stylitics knows the item by (catalog sync identifier). */
interface StyliticsAnchorSkuResolverContract
{
    public function resolveSku(int $shopProductId, ?int $shopVariantId = null): string;
}
