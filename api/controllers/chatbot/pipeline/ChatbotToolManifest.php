<?php

/**
 * Reader for the chatbot tool manifest (config/chatbot_tools.php).
 * Planner, validator and prompts query routing intent here instead of
 * duplicating intent -> tool maps in switch statements.
 */
class ChatbotToolManifest {
    private static ?array $manifest = null;

    public static function load(): array {
        if (self::$manifest === null) {
            $manifest = require __DIR__ . '/../../../../config/chatbot_tools.php';
            if (!is_array($manifest) || !isset($manifest['intents']) || !is_array($manifest['intents'])) {
                throw new RuntimeException('Invalid chatbot tool manifest');
            }
            self::$manifest = $manifest['intents'];
        }
        return self::$manifest;
    }

    public static function reset(): void {
        self::$manifest = null;
    }

    /** @return string[] */
    public static function knownIntents(): array {
        return array_keys(self::load());
    }

    /** @return string[] */
    public static function knownEntityFields(): array {
        $manifest = require __DIR__ . '/../../../../config/chatbot_tools.php';
        return is_array($manifest['entity_fields'] ?? null) ? $manifest['entity_fields'] : [];
    }

    /** @return string[] ordered tool names for the intent, [] when terminal */
    public static function toolsFor(string $intent): array {
        $entry = self::load()[$intent] ?? null;
        if (!is_array($entry)) return [];
        return array_values(array_filter(
            array_map('strval', (array)($entry['tools'] ?? [])),
            fn(string $tool) => $tool !== ''
        ));
    }

    /** @return string[] required entity slots for the intent */
    public static function requiredSlots(string $intent): array {
        $entry = self::load()[$intent] ?? null;
        if (!is_array($entry)) return [];
        return array_values((array)($entry['required_slots'] ?? []));
    }

    public static function emptyResponse(string $intent): string {
        $entry = self::load()[$intent] ?? null;
        $response = is_array($entry) ? (string)($entry['empty_response'] ?? 'fallback') : 'fallback';
        return in_array($response, ['fallback', 'clarification', 'final_answer'], true) ? $response : 'fallback';
    }

    public static function needsAuth(string $intent): bool {
        $entry = self::load()[$intent] ?? null;
        return is_array($entry) && ($entry['needs_auth'] ?? false) === true;
    }

    public static function isMutation(string $intent): bool {
        $entry = self::load()[$intent] ?? null;
        return is_array($entry) && ($entry['mutation'] ?? false) === true;
    }
}
