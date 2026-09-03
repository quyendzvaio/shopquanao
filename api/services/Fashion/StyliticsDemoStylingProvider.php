<?php

/** Deterministic local references used only when STYLITICS_PROVIDER_MODE=demo. */
final class StyliticsDemoStylingProvider implements StylingReferenceProvider
{
    public function referencesForAnchor(int $shopProductId, ?int $shopVariantId = null): StyleReferenceSet
    {
        if ($shopProductId <= 0) throw new InvalidArgumentException('shopProductId must be positive');
        return new StyleReferenceSet($shopProductId, 'office', [
            new StyleReference('bottom', 'trousers', 'chinos', ['navy'], ['cotton'], ['tailored'], ['office'], null, 'tailored navy chinos', null, 'stylitics_demo', 'demo-bottom-' . $shopProductId, 0.7),
            new StyleReference('shoe', 'footwear', 'derby', ['brown'], ['leather'], ['classic'], ['office'], null, 'brown leather derby shoes', null, 'stylitics_demo', 'demo-shoe-' . $shopProductId, 0.7),
            new StyleReference('accessory', 'accessory', 'belt', ['brown'], ['leather'], ['minimal'], [], null, 'brown leather belt', null, 'stylitics_demo', 'demo-belt-' . $shopProductId, 0.6),
        ], 'stylitics_demo');
    }
}
