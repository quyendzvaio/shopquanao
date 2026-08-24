<?php

final class FashionDomainTest extends \PHPUnit\Framework\TestCase
{
    public function testStructuredPlanIsValidatedAndSortedByPriority(): void
    {
        $plan = ComplementaryPlan::fromArray([
            'anchor_product_id' => 50,
            'requirements' => [
                ['category' => 'shoes', 'styles' => ['minimal'], 'colors' => ['white'], 'priority' => 2],
                ['category' => 'trousers', 'styles' => ['tailored'], 'colors' => ['beige'], 'priority' => 1],
            ],
        ]);

        $this->assertSame(50, $plan->anchorProductId);
        $this->assertSame('trousers', $plan->requirements[0]->category);
        $this->assertSame('shoes', $plan->requirements[1]->category);
        $this->assertSame('white', $plan->requirements[1]->colors[0]);
    }

    public function testPlanRejectsRawProseInsteadOfStructuredRequirements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComplementaryPlan::fromArray([
            'anchor_product_id' => 50,
            'requirements' => 'Wear it with beige trousers.',
        ]);
    }

    public function testRequirementRejectsMeaninglessTerms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ComplementaryItemRequirement::fromArray(['category' => '---']);
    }

    public function testProviderResultDoesNotExposeRawProviderFailureShape(): void
    {
        $result = FashionProviderResult::failure('timeout', 'FindMine request timed out', true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('timeout', $result->errorCode);
        $this->assertTrue($result->retryable);
    }
}
