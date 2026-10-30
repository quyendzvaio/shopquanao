<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "còn hàng" search where every match is sold out: search_products reports out_of_stock_matches,
 * and the answer says the products exist but are sold out instead of "chưa tìm thấy".
 */
final class OutOfStockMatchesTest extends TestCase
{
    private function intent(): array
    {
        return [
            'primary_intent' => 'product_search',
            'entities' => ['product_type' => 'quần baggy', 'in_stock' => true],
            'requested_fields' => ['stock'], 'missing_slots' => [],
            'original_query' => 'quần baggy còn hàng không',
        ];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [loop result, response] */
    private function runTurn(StubOutcomeGateway $gateway): array
    {
        $intent = $this->intent();
        $caps = CapabilityRegistry::fromToolDefinitions($gateway->getDefinitions());
        $loop = (new EvidenceExecutionLoop($gateway, $caps))->run((string)$intent['original_query'], $intent, []);
        $response = (new ResponseGenerator())->generate((string)$intent['original_query'], $intent, $loop['normalized'], $loop['plan']);
        return [$loop, $response];
    }

    private function emptySearch(?int $outOfStock): array
    {
        $result = ['products' => [], 'pagination' => ['page' => 1, 'limit' => null, 'total' => 0, 'total_pages' => 1]];
        if ($outOfStock !== null) {
            $result['out_of_stock_matches'] = $outOfStock;
        }
        return $result;
    }

    public function testSoldOutMatchesAreReportedAsSoldOut(): void
    {
        $gateway = new StubOutcomeGateway(['search_products' => $this->emptySearch(2)]);
        [$loop, $response] = $this->runTurn($gateway);

        $this->assertSame('return', $loop['decision']['action']);
        $this->assertSame('all_matches_out_of_stock', $loop['decision']['reason']);
        $this->assertSame(1, $gateway->calls, 'no query rewrite: the products exist, they are sold out');
        $this->assertSame('final_answer', $response['response_type']);
        $this->assertSame([], $response['products']);
        $this->assertSame(
            'Shop có 2 mẫu quần baggy nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.',
            $response['message']
        );
    }

    public function testNoMatchAtAllKeepsNotFoundAnswer(): void
    {
        [$loop, $response] = $this->runTurn(new StubOutcomeGateway(['search_products' => $this->emptySearch(0)]));

        $this->assertNotSame('all_matches_out_of_stock', $loop['decision']['reason']);
        $this->assertStringStartsWith('Mình chưa tìm thấy quần baggy phù hợp.', $response['message']);
    }

    public function testMissingCountKeepsNotFoundAnswer(): void
    {
        [, $response] = $this->runTurn(new StubOutcomeGateway(['search_products' => $this->emptySearch(null)]));

        $this->assertStringStartsWith('Mình chưa tìm thấy quần baggy phù hợp.', $response['message']);
    }
}
