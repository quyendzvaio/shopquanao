<?php

require_once __DIR__ . '/PartialParseResult.php';
require_once __DIR__ . '/DeterministicIntentParser.php';
require_once __DIR__ . '/ConflictDetector.php';
require_once __DIR__ . '/ConflictResolver.php';
require_once __DIR__ . '/MergeEngine.php';

class IntentAndConstraintExtractor {
    public function extract(string $message, array $memoryContext = []): array {
        $parser = new DeterministicIntentParser();
        $partial = $parser->parse($message, $memoryContext)->toArray();
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $conflictResolution = (new ConflictResolver())->resolve($partial);

        return (new MergeEngine())->merge(
            $partial,
            ['used' => false, 'inferred_fields' => [], 'unresolved_remaining' => [], 'error' => null],
            $memoryContext,
            $conflictResolution
        );
    }

}
