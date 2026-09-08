<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The planner must derive tool selection from the manifest, not from a
 * hardcoded switch, so adding a tool cannot silently desync routing.
 */
final class ManifestPlannerTest extends TestCase
{
    private ToolPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ToolPlanner([]);
    }

    public function testUnknownPlansNoToolsAndFallback(): void
    {
        $plan = $this->planner->plan(['primary_intent' => 'unknown', 'entities' => []]);
        $this->assertSame([], $plan['batches']);
        $this->assertSame('fallback', $plan['response_type']);
    }

    public function testProductDetailWithoutIdPlansNoTools(): void
    {
        $plan = $this->planner->plan(['primary_intent' => 'product_detail', 'entities' => []]);
        $this->assertSame([], $plan['batches']);
    }

    public function testProductDetailWithIdPlansDetailTool(): void
    {
        $plan = $this->planner->plan([
            'primary_intent' => 'product_detail',
            'entities' => ['product_id' => 52],
        ]);
        $tools = array_column($plan['batches'][0], 'tool');
        $this->assertContains('get_product_detail', $tools);
    }

    public function testGuardrailIntentsPlanNoTools(): void
    {
        foreach (['unsupported_outfit', 'unsupported_checkout'] as $intent) {
            $plan = $this->planner->plan(['primary_intent' => $intent, 'entities' => ['product_id' => 52]]);
            $this->assertSame([], $plan['batches'], $intent);
        }
    }

    public function testPlannerOutputMatchesManifest(): void
    {
        $cases = [
            'product_search' => ['product_type' => 'áo'],
            'product_detail' => ['product_id' => 52],
            'size_advice' => ['height' => 170, 'weight' => 65],
            'return_exchange' => [],
            'shipping' => [],
            'policy' => [],
            'order_status' => [],
            'mixed_product_policy' => ['product_id' => 52],
            'mixed_product_policy_search' => null,
            'suggest_complementary_products' => ['product_id' => 52],
        ];
        foreach ($cases as $intent => $entities) {
            if ($entities === null) {
                $intent = 'mixed_product_policy';
                $entities = ['product_type' => 'áo'];
            }
            $plan = $this->planner->plan(
                ['primary_intent' => $intent, 'entities' => $entities, 'missing_slots' => []]
            );
            $planned = $plan['batches'] === []
                ? []
                : array_column($plan['batches'][0], 'tool');
            // Planned tools must be a subset of the manifest allow-list: the
            // manifest declares what MAY run, builders decide what MUST run.
            foreach ($planned as $tool) {
                $this->assertContains(
                    $tool,
                    ChatbotToolManifest::toolsFor($intent),
                    "planner selected unmanifested tool {$tool} for {$intent}"
                );
            }
            $this->assertNotEmpty($planned, "planner selected nothing for {$intent}");
        }
    }

    public function testComplementaryWithoutAnchorAsksClarification(): void
    {
        $plan = $this->planner->plan([
            'primary_intent' => 'suggest_complementary_products',
            'entities' => [],
        ]);
        $this->assertSame([], $plan['batches']);
        $this->assertSame('clarification', $plan['response_type']);
    }
}
