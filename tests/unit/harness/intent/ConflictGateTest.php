<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Conflict gating (SRS FR-011): contradictory execution values without a
 * correction signal must block tool execution; an explicit correction wins.
 */
final class ConflictGateTest extends TestCase
{
    private function resolve(string $message): array
    {
        $parser = new DeterministicIntentParser();
        $partial = $parser->parse($message, [])->toArray();
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        return [
            'partial' => $partial,
            'resolution' => (new ConflictResolver())->resolve($partial),
        ];
    }

    public function testConflictingBudgetsWithoutCorrectionStayUnresolved(): void
    {
        $result = $this->resolve('tìm áo dưới 500k nhưng cũng dưới 300k');
        $this->assertNotEmpty($result['resolution']['unresolved_conflicts'] ?? []);
    }

    public function testExplicitCorrectionSelectsLastBudget(): void
    {
        $result = $this->resolve('tìm áo dưới 500k, à không, chốt dưới 300k');
        $this->assertEmpty($result['resolution']['unresolved_conflicts'] ?? []);
        $this->assertSame(
            300000,
            (int)($result['resolution']['resolved_fields']['max_price']['value'] ?? 0)
        );
    }

    public function testUnresolvedConflictBlocksPlanning(): void
    {
        $result = $this->resolve('tìm áo dưới 500k nhưng cũng dưới 300k');
        $this->assertNotEmpty($result['resolution']['unresolved_conflicts'] ?? []);
        // Planner must never run while an execution conflict is unresolved:
        // the service layer asks clarification instead (response_type).
        $this->assertSame('L0', TurnTierGate::tier($result['partial']));
    }

    public function testShippingThresholdIsNotAProductBudgetConflict(): void
    {
        // SRS FR-011: a shipping-scope price ("đơn dưới 500k") must not
        // conflict with the product budget ("áo thun dưới 300k").
        $result = $this->resolve('tìm áo thun dưới 300k và cho biết đơn dưới 500k tính phí ship thế nào');
        $this->assertEmpty($result['resolution']['unresolved_conflicts'] ?? []);
        $this->assertSame(
            300000,
            (int)($result['partial']['resolved_fields']['max_price']['value'] ?? 0)
        );
    }
}
