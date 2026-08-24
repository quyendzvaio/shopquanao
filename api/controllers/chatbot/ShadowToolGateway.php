<?php

require_once __DIR__ . '/contracts/ChatbotToolGateway.php';

/** Executes MCP as primary and compares it with the legacy gateway during rollout. */
final class ShadowToolGateway implements ChatbotToolGateway
{
    public function __construct(
        private ChatbotToolGateway $primary,
        private ChatbotToolGateway $legacy
    ) {
    }

    public function getDefinitions(): array
    {
        return $this->primary->getDefinitions();
    }

    public function execute(string $toolName, array $arguments): array
    {
        try {
            $primary = $this->primary->execute($toolName, $arguments);
        } catch (Throwable $error) {
            error_log("MCP shadow primary failed for $toolName: " . $error->getMessage());
            return $this->legacy->execute($toolName, $arguments);
        }
        try {
            $legacy = $this->legacy->execute($toolName, $arguments);
            $primaryHash = hash('sha256', json_encode($primary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $legacyHash = hash('sha256', json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!hash_equals($primaryHash, $legacyHash)) {
                error_log("MCP shadow mismatch for $toolName primary=$primaryHash legacy=$legacyHash");
            }
        } catch (Throwable $error) {
            error_log("MCP shadow legacy failed for $toolName: " . $error->getMessage());
        }
        return $primary;
    }
}
