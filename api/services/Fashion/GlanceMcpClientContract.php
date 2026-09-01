<?php

interface GlanceMcpClientContract
{
    /** @return array<string,mixed> */
    public function call(string $toolName, array $arguments): array;
}
