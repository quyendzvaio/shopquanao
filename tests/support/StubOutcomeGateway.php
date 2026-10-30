<?php

declare(strict_types=1);

/** Stub gateway: each tool either returns a canned result or throws. */
final class StubOutcomeGateway implements ChatbotToolGateway
{
    public int $calls = 0;

    /** @param array<string,array<string,mixed>|Throwable> $outcomes */
    public function __construct(private array $outcomes)
    {
    }

    public function getDefinitions(): array
    {
        return ToolDefinitionCatalog::chatbotDefinitions();
    }

    public function execute(string $toolName, array $arguments): array
    {
        $this->calls++;
        $outcome = $this->outcomes[$toolName] ?? new RuntimeException("unexpected tool $toolName");
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        return $outcome;
    }
}
