<?php

/** Boundary seam for the Stylitics Complete the Look REST API. */
interface StyliticsHttpClientContract
{
    /** @return array<string,mixed> raw decoded JSON response */
    public function completeTheLook(string $anchorSku, ?string $anchorVariantSku = null): array;
}
