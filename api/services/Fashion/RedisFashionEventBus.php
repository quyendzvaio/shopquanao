<?php
final class RedisFashionEventBus implements FashionEventBus
{
    public function __construct(private Redis $redis, private string $stream = 'fashion:events') {}

    public static function fromEnvironment(): self
    {
        if (!class_exists('Redis')) throw new RuntimeException('PHP Redis extension is unavailable');
        $redis = new Redis();
        $redis->connect((string)(getenv('REDIS_HOST') ?: 'redis'), (int)(getenv('REDIS_PORT') ?: 6379), (float)(getenv('REDIS_TIMEOUT') ?: 1.0));
        return new self($redis, (string)(getenv('FASHION_EVENT_STREAM') ?: 'fashion:events'));
    }

    public function publish(array $event): string
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = $this->redis->xAdd($this->stream, '*', ['event_id'=>(string)$event['event_id'],'event_type'=>(string)$event['event_type'],'payload'=>$payload]);
        if (!is_string($id) || $id === '') throw new RuntimeException('Redis Streams publish failed');
        return $id;
    }
}
