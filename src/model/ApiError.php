<?php

/** A failure the REST API answers with an HTTP status: the message is the response message, the code the status. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status);
    }
}
