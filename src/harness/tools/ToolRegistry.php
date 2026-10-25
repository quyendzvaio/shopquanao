<?php

/**
 * In-process chatbot tool gateway (CHATBOT_TOOL_TRANSPORT=internal|shadow).
 * Contracts come from ToolDefinitionCatalog; execution is delegated to the
 * same ToolApplicationService the MCP bridge uses, so both transports share
 * one implementation.
 */
class ToolRegistry implements ChatbotToolGateway
{
    private ToolApplicationService $tools;

    public function __construct(PDO $pdo, ?int $userId, ServiceConfig $config, FreeShippingPolicy $freeShipping)
    {
        $this->tools = new ToolApplicationService($pdo, $userId, $config, $freeShipping);
    }

    public function getDefinitions(): array
    {
        return ToolDefinitionCatalog::chatbotDefinitions();
    }

    public function execute(string $toolName, array $arguments): array
    {
        $allowed = array_map(
            static fn(array $definition): string => (string) ($definition['function']['name'] ?? ''),
            $this->getDefinitions()
        );
        if (!in_array($toolName, $allowed, true)) {
            throw new InvalidArgumentException('Unknown tool: ' . $toolName);
        }

        return $this->tools->execute($toolName, $arguments);
    }
}
