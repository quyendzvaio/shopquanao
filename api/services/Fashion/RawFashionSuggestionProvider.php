<?php

interface RawFashionSuggestionProvider
{
    /** @return list<RawFashionSuggestion> */
    public function suggestForAnchor(int $shopProductId, ?int $shopVariantId = null): array;
}
