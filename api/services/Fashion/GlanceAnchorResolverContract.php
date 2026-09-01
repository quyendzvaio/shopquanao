<?php

interface GlanceAnchorResolverContract
{
    public function resolve(int $shopProductId, ?int $shopVariantId = null): GlanceAnchorReference;
}
