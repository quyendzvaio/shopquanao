<?php

/** Provider-neutral source of styling references (never shop products). */
interface StylingReferenceProvider
{
    public function referencesForAnchor(int $shopProductId, ?int $shopVariantId = null): StyleReferenceSet;
}
