<?php
final class CartItemAddedConsumer
{
    public function __construct(private PDO $pdo, private ProactiveStylingStateStore $states, private ProactiveStylingStateMachine $machine, private string $consumerName='proactive-styling') {}

    /** @param array<string,mixed> $event */
    public function consume(array $event): bool
    {
        foreach (['event_id','event_type','event_version','user_id','session_id','cart_id','product_id','occurred_at'] as $field) {
            if (!array_key_exists($field,$event)) throw new InvalidArgumentException("Event is missing $field");
        }
        if ($event['event_type']!=='cart.item_added' || (int)$event['event_version']!==1) throw new InvalidArgumentException('Unsupported cart event');
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('INSERT INTO fashion_consumed_events (consumer_name,event_id) VALUES (?,?)');
            try { $stmt->execute([$this->consumerName,(string)$event['event_id']]); }
            catch (PDOException $error) {
                if (!$this->isDuplicateEvent($error)) throw $error;
                $this->pdo->rollBack();
                return false;
            }
            $userId=(int)$event['user_id']; $sessionId=(string)$event['session_id'];
            $state=$this->machine->onCartItemAdded($this->states->get($userId,$sessionId),(int)$event['product_id'],isset($event['variant_id'])?(int)$event['variant_id']:null,(string)$event['event_id']);
            $this->states->put($userId,$sessionId,$state); $this->pdo->commit(); return true;
        } catch (Throwable $error) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $error; }
    }

    private function isDuplicateEvent(PDOException $error): bool
    {
        $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if ($sqlState === '23505' || $driverCode === 1062) return true;
        return $sqlState === '23000'
            && preg_match('/duplicate|unique constraint/i', $error->getMessage()) === 1;
    }
}
