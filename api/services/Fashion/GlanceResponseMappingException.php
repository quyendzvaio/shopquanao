<?php

final class GlanceResponseMappingException extends RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}
