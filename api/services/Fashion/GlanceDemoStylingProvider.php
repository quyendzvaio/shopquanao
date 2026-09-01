<?php

/** Deterministic local references used only when GLANCE_PROVIDER_MODE=demo. */
final class GlanceDemoStylingProvider implements StylingReferenceProvider
{
    public function referencesForAnchor(int $shopProductId, ?int $shopVariantId = null): StyleReferenceSet
    {
        if ($shopProductId <= 0) throw new InvalidArgumentException('shopProductId must be positive');
        return new StyleReferenceSet($shopProductId, 'smart_casual', [
            new StyleReference('bottom', 'trousers', null, ['gray'], [], ['tailored'], ['smart_casual'], null, 'tailored gray trousers', null, 'glance_demo', 'demo-bottom-' . $shopProductId, 0.7),
            new StyleReference('shoe', 'footwear', 'loafers', ['brown'], ['leather'], ['minimal'], ['smart_casual'], null, 'brown leather loafers', null, 'glance_demo', 'demo-shoe-' . $shopProductId, 0.7),
            new StyleReference('accessory', 'accessory', 'belt', ['brown'], ['leather'], ['minimal'], [], null, 'brown leather belt', null, 'glance_demo', 'demo-accessory-' . $shopProductId, 0.6),
        ], 'glance_demo');
    }
}
