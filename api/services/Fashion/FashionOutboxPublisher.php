<?php
final class FashionOutboxPublisher
{
    public function __construct(private PDO $pdo, private FashionEventBus $bus) {}

    /** @return array{processed:int,published:int,failed:int} */
    public function runBatch(int $limit = 100): array
    {
        $limit=max(1,min(500,$limit)); $report=['processed'=>0,'published'=>0,'failed'=>0];
        $rows=$this->pdo->query("SELECT id,payload FROM fashion_event_outbox WHERE status='pending' AND available_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $report['processed']++;
            try {
                $event=json_decode((string)$row['payload'],true,512,JSON_THROW_ON_ERROR);
                $this->bus->publish($event);
                $this->pdo->prepare("UPDATE fashion_event_outbox SET status='published',published_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=? AND status='pending'")->execute([(int)$row['id']]);
                $report['published']++;
            } catch (Throwable $error) {
                $this->pdo->prepare("UPDATE fashion_event_outbox SET attempts=attempts+1,last_error=?,available_at=? WHERE id=? AND status='pending'")
                    ->execute([mb_substr($error->getMessage(),0,2000),gmdate('Y-m-d H:i:s',time()+30),(int)$row['id']]);
                $report['failed']++;
            }
        }
        return $report;
    }
}
