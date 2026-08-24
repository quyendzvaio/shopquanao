<?php

interface FashionProvider
{
    public function completeTheLook(AnchorProductRef $anchor): FashionProviderResult;
}
