<?php
final class ProactiveChatTurnServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testOnlySecondSuitableUserTurnCallsStylingToolAndShowsOnce(): void
    {
        $pdo=$this->database(); $store=new ProactiveStylingStateStore($pdo); $machine=new ProactiveStylingStateMachine();
        $store->put(7,'42',$machine->onCartItemAdded([],50,null,'event-1'));
        $tools=new ProactiveRecordingGateway([['id'=>65,'name'=>'Quần thật']]);
        $service=new ProactiveChatTurnService($store,$machine,$tools);
        self::assertSame('waiting_for_turn',$service->handle(7,'42','product_search')['status']);
        self::assertSame(0,$tools->calls);
        self::assertSame('waiting_for_suitable_context',$service->handle(7,'42','shipping')['status']);
        self::assertSame(0,$tools->calls);
        $shown=$service->handle(7,'42','product_search');
        self::assertSame('shown',$shown['status']);
        self::assertSame(1,$tools->calls);
        self::assertSame(3,$shown['reference_count']);
        self::assertSame(125,$shown['timings']['styling_reference_provider_ms']);
        self::assertSame('not_armed',$service->handle(7,'42','product_search')['status']);
        self::assertSame(1,$tools->calls);
    }

    public function testProviderOrExtractionFailureIsRetryableAndDoesNotMarkAnchorSuggested(): void
    {
        $pdo=$this->database(); $store=new ProactiveStylingStateStore($pdo); $machine=new ProactiveStylingStateMachine();
        $store->put(9,'failure-session',$machine->onCartItemAdded([],77,null,'event-failure'));
        $tools=new ProactiveThrowingGateway();
        $service=new ProactiveChatTurnService($store,$machine,$tools);

        self::assertSame('waiting_for_turn',$service->handle(9,'failure-session','product_search')['status']);
        self::assertSame('tool_retryable_failure',$service->handle(9,'failure-session','product_search')['status']);
        self::assertSame(1,$tools->calls);
        self::assertNull($store->get(9,'failure-session')['suggested_anchor_product_id']);
        self::assertTrue($store->get(9,'failure-session')['eligible']);
    }
    private function database(): PDO
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE proactive_styling_state(user_id INTEGER,session_id TEXT,pending_product_id INTEGER,pending_variant_id INTEGER,remaining_user_turns INTEGER,source_event_id TEXT,state_version INTEGER DEFAULT 0,eligible INTEGER,status TEXT DEFAULT 'not_armed',failure_reason TEXT,retry_count INTEGER DEFAULT 0,last_attempt_at TEXT,suggested_anchor_product_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,session_id))");
        return $pdo;
    }
}
final class ProactiveThrowingGateway implements ChatbotToolGateway
{
    public int $calls=0;
    public function getDefinitions(): array{return [];}
    public function execute(string $toolName,array $arguments): array{$this->calls++;throw new RuntimeException('extraction failed');}
}
final class ProactiveRecordingGateway implements ChatbotToolGateway
{
    public int $calls=0;
    public function __construct(private array $products){}
    public function getDefinitions(): array{return [];}
    public function execute(string $toolName,array $arguments): array{$this->calls++;return ['products'=>$this->products,'groups'=>[],'reference_count'=>3,'timings'=>['styling_reference_provider_ms'=>125]];}
}
