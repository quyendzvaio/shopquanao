<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitPlanningTest extends TestCase
{
    public function testPlansBuildOutfitWithBudgetSizeAndMeasurements(): void
    {
        $plan = (new ToolPlanner([]))->plan([
            'primary_intent' => 'build_outfit',
            'entities' => ['style_tags' => ['phongvan', 'bogus'], 'max_price' => 800000, 'height' => 170, 'weight' => 65, 'size' => 'M'],
            'missing_slots' => [],
        ]);

        $call = $plan['batches'][0][0];
        $this->assertSame('build_outfit', $call['tool']);
        $this->assertSame(
            ['style_tags' => ['phongvan'], 'budget' => 800000, 'size' => 'M', 'height' => 170, 'weight' => 65],
            $call['args']
        );
    }

    public function testAnchorWithoutTagsIsEnoughButNeitherAsksForClarification(): void
    {
        $planner = new ToolPlanner([]);
        $anchored = $planner->plan(['primary_intent' => 'build_outfit', 'entities' => ['product_id' => 61], 'missing_slots' => []]);
        $this->assertSame(['product_id' => 61], $anchored['batches'][0][0]['args']);

        $empty = $planner->plan(['primary_intent' => 'build_outfit', 'entities' => [], 'missing_slots' => []]);
        $this->assertSame([], $empty['batches']);
        $this->assertSame('clarification', $empty['response_type']);
    }

    private function realCapabilities(): array
    {
        return CapabilityRegistry::fromToolDefinitions(ToolDefinitionCatalog::chatbotDefinitions());
    }

    public function testValidatorAcceptsPlannedOutfitCall(): void
    {
        $caps = $this->realCapabilities();
        $intent = ['primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['phongvan'], 'max_price' => 800000, 'size' => 'M'], 'requested_fields' => [], 'original_query' => 'đồ đi phỏng vấn'];
        $plan = (new ToolPlanner($caps))->plan($intent);

        $result = (new PlanValidator($caps))->validate($plan, $intent);

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['passed']);
    }

    public function testValidatorRejectsProductSearchPlanForOutfits(): void
    {
        $intent = ['primary_intent' => 'build_outfit', 'entities' => ['style_tags' => ['dichoi']], 'requested_fields' => [], 'original_query' => 'set đồ đi chơi'];
        $plan = ['batches' => [[['tool' => 'search_products', 'args' => ['search' => 'áo'], 'id' => 'x']]]];

        $errors = (new PlanValidator($this->realCapabilities()))->validate($plan, $intent)['errors'];

        $this->assertContains('build_outfit_requires_build_outfit', $errors);
        $this->assertContains('build_outfit_must_use_outfit_tool', $errors);
    }

    public function testOutfitWithoutOccasionAsksUserInsteadOfFallingBack(): void
    {
        $intent = ['primary_intent' => 'build_outfit', 'entities' => [], 'missing_slots' => ['style_tags']];
        $plan = (new ToolPlanner([]))->plan($intent);
        $decision = (new EvidenceDecisionRouter())->decide($intent, $plan, [], [], ['passed' => false], [], false);

        $this->assertSame('clarification', $plan['response_type']);
        $this->assertSame('ask_user', $decision['action']);
    }
}
