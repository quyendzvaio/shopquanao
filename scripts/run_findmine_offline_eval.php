<?php
require_once __DIR__ . '/../tests/bootstrap.php';
$cases = require __DIR__ . '/../eval/findmine_agent_eval_cases.php';
if (count($cases) !== 70) throw new RuntimeException('Evaluation corpus must contain exactly 70 cases');
$parser = new DeterministicIntentParser();
$stateMachine = new ProactiveStylingStateMachine();
$passed = 0;
foreach ($cases as $case) {
    $parsed = $parser->parse($case['message']); $field = $parsed->toArray()['resolved_fields']['intent'] ?? null;
    $intent = is_array($field) ? ($field['value'] ?? null) : $field;
    if (isset($case['expected_intent'])) {
        $ok = $intent === $case['expected_intent'];
    } else {
        $state = $stateMachine->onCartItemAdded([], 50, null, 'offline-' . $case['id']);
        $transition = $stateMachine->onUserTurn($state, true, false);
        $ok = $case['expected_action'] === 'pending_turn'
            && $transition['action'] === 'silent'
            && (int) $transition['state']['remaining_user_turns'] === 1;
    }
    if ($ok) $passed++;
}
printf("cases=%d passed=%d failed=%d provider_calls=0\n", count($cases), $passed, count($cases)-$passed);
exit($passed === count($cases) ? 0 : 1);
