<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The tool manifest is the single source of truth for intent -> tool routing.
 * Every entry must be complete so planner, validator and prompts can render
 * from it without scattered switch statements.
 */
final class ChatbotToolManifestTest extends TestCase
{
    private array $manifest;

    protected function setUp(): void
    {
        $this->manifest = ChatbotToolManifest::load();
    }

    public function testManifestCoversAllProductionIntents(): void
    {
        foreach (ChatbotToolManifest::knownIntents() as $intent) {
            $this->assertArrayHasKey(
                $intent,
                $this->manifest,
                "intent {$intent} has no manifest entry"
            );
        }
    }

    public function testEveryEntryHasRequiredShape(): void
    {
        foreach ($this->manifest as $intent => $entry) {
            $this->assertIsArray($entry, "entry {$intent}");
            foreach (['tools', 'needs_auth', 'mutation'] as $key) {
                $this->assertArrayHasKey($key, $entry, "entry {$intent} missing {$key}");
            }
            $this->assertIsArray($entry['tools']);
            $this->assertIsBool($entry['needs_auth']);
            $this->assertIsBool($entry['mutation']);
        }
    }

    public function testGuardrailAndUnknownIntentsSelectNoTools(): void
    {
        foreach (['unknown', 'unsupported_outfit', 'unsupported_checkout'] as $intent) {
            $this->assertSame(
                [],
                ChatbotToolManifest::toolsFor($intent),
                "{$intent} must not select tools"
            );
        }
    }

    public function testMutationToolsRequireAuthAndConfirmation(): void
    {
        $mutations = [];
        foreach ($this->manifest as $intent => $entry) {
            if ($entry['mutation'] === true) {
                $mutations[] = $intent;
                $this->assertTrue(
                    $entry['needs_auth'],
                    "mutation intent {$intent} must require auth"
                );
                $this->assertContains(
                    'confirmation',
                    $entry['required_slots'] ?? [],
                    "mutation intent {$intent} must require confirmation slot"
                );
            }
        }
        // Current policy: the chatbot exposes no mutation tools at all
        // (no cart/checkout through chat). If one is ever added, the guards
        // above apply automatically.
        $this->assertSame([], $mutations);
    }

    public function testRequiredSlotsReferenceKnownEntityFields(): void
    {
        $known = ChatbotToolManifest::knownEntityFields();
        foreach ($this->manifest as $intent => $entry) {
            foreach ($entry['required_slots'] ?? [] as $slot) {
                $this->assertContains($slot, $known, "intent {$intent} slot {$slot} unknown");
            }
        }
    }

    public function testProductDetailRequiresProductId(): void
    {
        $this->assertContains('product_id', $this->manifest['product_detail']['required_slots']);
    }
}
