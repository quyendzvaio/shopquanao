<?php

/**
 * The PHP side of the tool bridge. POST /api/internal/mcp (Internal/mcp.php) is
 * the only caller: chat-graph's in-process mcp-server posts every tool call there.
 */
final class AgentFactory
{
    /**
     * Runs one tool for the internal MCP bridge with the environment's service
     * config and free-shipping policy. Only tools listed in ToolDefinitionCatalog
     * run, so the cart and checkout operations ToolApplicationService still
     * implements are unreachable over MCP; any other name is an
     * InvalidArgumentException ("Unknown tool", HTTP 400). Exceptions from the tool
     * propagate unchanged (InvalidArgumentException for bad input, RuntimeException
     * for auth and other runtime errors).
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function executeTool(PDO $pdo, ?int $userId, string $tool, array $arguments): array
    {
        $allowed = array_map(
            static fn(array $definition): string => (string) ($definition['function']['name'] ?? ''),
            ToolDefinitionCatalog::chatbotDefinitions()
        );
        if (!in_array($tool, $allowed, true)) {
            throw new InvalidArgumentException('Unknown tool: ' . $tool);
        }

        $config = HarnessConfig::fromEnvironment();
        return (new ToolApplicationService($pdo, $userId, $config->services, $config->freeShipping))->execute($tool, $arguments);
    }
}
