<?php

interface FashionAttributeExtractor
{
    /** @param list<RawFashionSuggestion> $suggestions @return list<ExtractedFashionItem> */
    public function extract(array $suggestions): array;
}
