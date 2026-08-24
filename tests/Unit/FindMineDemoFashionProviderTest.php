<?php

use PHPUnit\Framework\TestCase;

final class FindMineDemoFashionProviderTest extends TestCase
{
    public function testDemoMcpItemsRemainRawSuggestionsAndNeverBecomeShopIds(): void
    {
        $client = new RecordingDemoFindMineClient([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'response_uuid' => 'demo-response-1',
                    'looks' => [[
                        'look_id' => 'demo-look-1',
                        'products' => [
                            ['item_id' => 'provider-900', 'title' => 'white denim trousers'],
                            ['item_id' => 'provider-901', 'title' => 'minimal black sneakers'],
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]],
        ]);

        $suggestions = (new FindMineDemoFashionProvider($client))->suggestForAnchor(51, 5101);

        self::assertSame(['white denim trousers', 'minimal black sneakers'], array_map(
            static fn (RawFashionSuggestion $suggestion): string => $suggestion->text,
            $suggestions
        ));
        self::assertSame('findmine_demo', $suggestions[0]->source);
        self::assertSame('provider-900', $suggestions[0]->providerContext['provider_item_id']);
        self::assertSame('demo-look-1', $suggestions[0]->providerContext['look_id']);
        self::assertSame('demo-response-1', $suggestions[0]->providerContext['response_uuid']);
        self::assertSame('shopquanao-demo-anchor-51', $client->arguments['product_id']);
        self::assertTrue($client->arguments['fake_result']);
        self::assertArrayNotHasKey('shop_product_id', $suggestions[0]->providerContext);
    }
}

final class RecordingDemoFindMineClient implements FindMineMcpClientContract
{
    public array $arguments = [];

    public function __construct(private array $response) {}
    public function initialize(): array { return []; }
    public function listTools(): array { return []; }
    public function call(string $toolName, array $arguments): array
    {
        $this->arguments = $arguments;
        return $this->response;
    }
}
