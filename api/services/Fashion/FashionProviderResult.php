<?php

final readonly class FashionProviderResult
{
    private function __construct(
        public ?ComplementaryPlan $plan,
        public ?string $errorCode,
        public ?string $errorMessage,
        public bool $retryable
    ) {
        if (($plan === null) === ($errorCode === null)) {
            throw new LogicException('A provider result must contain exactly one of plan or error');
        }
    }

    public static function success(ComplementaryPlan $plan): self
    {
        return new self($plan, null, null, false);
    }

    public static function failure(string $code, string $message, bool $retryable = false): self
    {
        $allowed = [
            'authentication_failed', 'timeout', 'provider_unavailable', 'invalid_response',
            'missing_required_field', 'empty_recommendation', 'unknown_provider_product',
            'invalid_request', 'rate_limited',
            'mapping_not_found', 'AUTHENTICATION_ERROR', 'PROVIDER_UNAVAILABLE', 'PROVIDER_TIMEOUT',
            'UNKNOWN_PROVIDER_PRODUCT', 'INVALID_PROVIDER_RESPONSE', 'EMPTY_RECOMMENDATION', 'RATE_LIMITED',
        ];
        if (!in_array($code, $allowed, true)) {
            throw new InvalidArgumentException('Unknown fashion provider error code');
        }
        return new self(null, $code, trim($message), $retryable);
    }

    public function isSuccess(): bool
    {
        return $this->plan !== null;
    }
}
