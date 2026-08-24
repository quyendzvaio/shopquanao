<?php

final class ProactiveStylingStateMachineTest extends \PHPUnit\Framework\TestCase
{
    public function testLatestAnchorWinsAndSuggestionOccursOnSecondSuitableUserTurn(): void
    {
        $machine = new ProactiveStylingStateMachine();
        $state = $machine->onCartItemAdded([], 10, 2, 'evt-1');
        $state = $machine->onCartItemAdded($state, 20, 3, 'evt-2');
        $first = $machine->onUserTurn($state, true, true);
        self::assertSame('silent', $first['action']);
        self::assertSame(1, $first['state']['remaining_user_turns']);
        $second = $machine->onUserTurn($first['state'], true, true);
        self::assertSame('suggest', $second['action']);
        self::assertSame(20, $second['state']['suggested_anchor_product_id']);
        self::assertSame('evt-2', $second['state']['source_event_id']);
        self::assertSame('silent', $machine->onUserTurn($second['state'], true, true)['action']);
    }

    public function testUnsuitableTurnCountsButSuppressesAtTriggerPoint(): void
    {
        $machine = new ProactiveStylingStateMachine();
        $state = $machine->onCartItemAdded([], 10, null, 'evt-1');
        $result = $machine->onUserTurn($state, false, true);
        self::assertSame('silent', $result['action']);
        self::assertSame(1, $result['state']['remaining_user_turns']);
        $suppressed = $machine->onUserTurn($result['state'], false, true);
        self::assertSame(0, $suppressed['state']['remaining_user_turns']);
        self::assertTrue($suppressed['state']['eligible']);
        self::assertSame('suggest', $machine->onUserTurn($suppressed['state'], true, true)['action']);
    }
}
