<?php

final class FindMineAgentEvaluationCorpusTest extends \PHPUnit\Framework\TestCase
{
    public function testCorpusContainsExactlySeventyUniqueQuestionsAcrossAllUseCases(): void
    {
        $cases = require ROOT_DIR . '/eval/findmine_agent_eval_cases.php';

        self::assertCount(70, $cases);
        self::assertCount(70, array_unique(array_column($cases, 'message')));
        $useCases = array_values(array_unique(array_column($cases, 'use_case')));
        sort($useCases);
        self::assertSame(
            ['explicit' => 20, 'proactive' => 20, 'suppression' => 15, 'unrelated' => 15],
            array_count_values(array_column($cases, 'class'))
        );
        self::assertSame(
            [
                'EXISTING_PRODUCT_SEARCH',
                'UC1_EXPLICIT_STYLING',
                'UC2_PROACTIVE_AFTER_CART',
                'UC2_SUPPRESSION',
            ],
            $useCases
        );
    }

    public function testEveryDeterministicQuestionMatchesItsExpectedContract(): void
    {
        $cases = require ROOT_DIR . '/eval/findmine_agent_eval_cases.php';
        $parser = new DeterministicIntentParser();
        $stateMachine = new ProactiveStylingStateMachine();

        foreach ($cases as $case) {
            if (isset($case['expected_intent'])) {
                $field = $parser->parse($case['message'])->toArray()['resolved_fields']['intent'] ?? null;
                $actual = is_array($field) ? ($field['value'] ?? null) : $field;
                self::assertSame($case['expected_intent'], $actual, $case['id']);
                continue;
            }

            $state = $stateMachine->onCartItemAdded([], 50, null, 'unit-' . $case['id']);
            $transition = $stateMachine->onUserTurn($state, true, false);
            self::assertSame('pending_turn', $case['expected_action'], $case['id']);
            self::assertSame('silent', $transition['action'], $case['id']);
            self::assertSame(1, (int) $transition['state']['remaining_user_turns'], $case['id']);
        }
    }
}
