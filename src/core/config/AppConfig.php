<?php

/**
 * Environment-derived settings of the web/app layer (browser chat guard,
 * internal MCP bridge token, env admin login), read once. Every key is read
 * with getenv only, as the app code did before.
 */
final readonly class AppConfig
{
    /**
     * @param list<string> $allowedOrigins browser origins allowed to call the chat endpoints; empty = same-origin rule
     */
    public function __construct(
        public array $allowedOrigins = [],
        public int $maxMessageChars = 2000,
        public string $mcpServiceToken = '',
        public string $adminEmail = 'admin@gmail.com',
        public string $adminUser = '',
        public string $adminPassword = '',
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            allowedOrigins: array_values(array_filter(array_map('trim', explode(',', (string) getenv('CHATBOT_ALLOWED_ORIGINS'))))),
            maxMessageChars: (int) (getenv('CHATBOT_MAX_MESSAGE_CHARS') ?: 2000),
            mcpServiceToken: (string) (getenv('MCP_SERVICE_TOKEN') ?: ''),
            adminEmail: getenv('ADMIN_EMAIL') ?: 'admin@gmail.com',
            adminUser: getenv('ADMIN_USER') ?: '',
            adminPassword: getenv('ADMIN_PASSWORD') ?: '',
        );
    }
}
