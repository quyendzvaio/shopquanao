<?php
final class FashionEventPipelineTest extends \PHPUnit\Framework\TestCase
{
    public function testAddingToCartArmsProactiveStateWithoutWaitingForWorkers(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price REAL,image TEXT,stock INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE cart(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,product_id INTEGER,quantity INTEGER,size TEXT)');
        $pdo->exec('CREATE TABLE chat_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,updated_at TEXT)');
        $pdo->exec("CREATE TABLE fashion_event_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,event_id TEXT UNIQUE,event_type TEXT,event_version INTEGER,aggregate_key TEXT,payload TEXT,status TEXT,attempts INTEGER DEFAULT 0,available_at TEXT DEFAULT CURRENT_TIMESTAMP,published_at TEXT,last_error TEXT)");
        $this->createStateTable($pdo);
        $pdo->exec('INSERT INTO products(id,stock) VALUES (54,3)');
        $pdo->exec("INSERT INTO chat_sessions(id,user_id,status,updated_at) VALUES (42,7,'active',CURRENT_TIMESTAMP)");

        (new CartService($pdo))->add(7, [
            'product_id' => 54,
            'quantity' => 1,
            'size' => 'S',
            'session_id' => '42',
        ]);

        $state = (new ProactiveStylingStateStore($pdo))->get(7, '42');
        self::assertSame(54, $state['pending_product_id'] ?? null);
        self::assertSame(2, $state['remaining_user_turns'] ?? null);
        self::assertTrue($state['eligible'] ?? false);
    }

    public function testOutboxPublishesAndConsumerIsIdempotentWithLatestAnchorWins(): void
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE fashion_event_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,event_id TEXT UNIQUE,event_type TEXT,event_version INTEGER,aggregate_key TEXT,payload TEXT,status TEXT,attempts INTEGER DEFAULT 0,available_at TEXT DEFAULT CURRENT_TIMESTAMP,published_at TEXT,last_error TEXT)");
        $pdo->exec("CREATE TABLE fashion_consumed_events(consumer_name TEXT,event_id TEXT,consumed_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(consumer_name,event_id))");
        $this->createStateTable($pdo);
        $outbox=new CartItemAddedOutbox($pdo);
        $first=$outbox->publish(7,'42',10,100,1001);
        $second=$outbox->publish(7,'42',11,200,2001);
        $bus=new RecordingFashionEventBus();
        $report=(new FashionOutboxPublisher($pdo,$bus))->runBatch();
        self::assertSame(['processed'=>2,'published'=>2,'failed'=>0],$report);
        self::assertSame(2,count($bus->events));

        $consumer=new CartItemAddedConsumer($pdo,new ProactiveStylingStateStore($pdo),new ProactiveStylingStateMachine());
        self::assertTrue($consumer->consume($bus->events[0]));
        self::assertTrue($consumer->consume($bus->events[1]));
        self::assertFalse($consumer->consume($bus->events[1]));
        $state=(new ProactiveStylingStateStore($pdo))->get(7,'42');
        self::assertSame(200,$state['pending_product_id']);
        self::assertSame(2001,$state['pending_variant_id']);
        self::assertSame(2,$state['remaining_user_turns']);
        self::assertSame($second,$state['source_event_id']);
        self::assertSame(2,$state['state_version']);
        self::assertNotSame($first,$second);

        // A delayed first event is consumed for idempotency/auditing but must
        // never replace the newer user-visible anchor.
        $delayedConsumer=new CartItemAddedConsumer($pdo,new ProactiveStylingStateStore($pdo),new ProactiveStylingStateMachine(),'delayed-consumer');
        self::assertTrue($delayedConsumer->consume($bus->events[0]));
        self::assertSame(200,(new ProactiveStylingStateStore($pdo))->get(7,'42')['pending_product_id']);
    }

    public function testConsumerRethrowsNonDuplicateDatabaseFailures(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE fashion_consumed_events(consumer_name TEXT,event_id TEXT,PRIMARY KEY(consumer_name,event_id))");
        $this->createStateTable($pdo);
        $pdo->exec("CREATE TRIGGER reject_consumed_event BEFORE INSERT ON fashion_consumed_events BEGIN SELECT RAISE(ABORT, 'storage unavailable'); END");
        $consumer = new CartItemAddedConsumer(
            $pdo,
            new ProactiveStylingStateStore($pdo),
            new ProactiveStylingStateMachine()
        );
        $event = [
            'event_id' => 'event-storage-failure',
            'event_type' => 'cart.item_added',
            'event_version' => 1,
            'user_id' => 7,
            'session_id' => '42',
            'cart_id' => 10,
            'product_id' => 100,
            'occurred_at' => gmdate(DATE_ATOM),
        ];

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('storage unavailable');
        $consumer->consume($event);
    }

    private function createStateTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE proactive_styling_state(user_id INTEGER,session_id TEXT,pending_product_id INTEGER,pending_variant_id INTEGER,remaining_user_turns INTEGER,source_event_id TEXT,state_version INTEGER DEFAULT 0,eligible INTEGER,status TEXT DEFAULT 'not_armed',failure_reason TEXT,retry_count INTEGER DEFAULT 0,last_attempt_at TEXT,suggested_anchor_product_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,session_id))");
    }
}
final class RecordingFashionEventBus implements FashionEventBus
{
    public array $events=[];
    public function publish(array $event): string { $this->events[]=$event; return (string)count($this->events).'-0'; }
}
