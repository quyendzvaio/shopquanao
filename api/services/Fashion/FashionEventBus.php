<?php
interface FashionEventBus
{
    /** @param array<string,mixed> $event */
    public function publish(array $event): string;
}
