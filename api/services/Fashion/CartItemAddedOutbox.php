<?php
final class CartItemAddedOutbox
{
    public function __construct(private PDO $pdo) {}
    public function publish(int $userId, string $sessionId, int $cartId, int $productId, ?int $variantId): string
    {
        $eventId = bin2hex(random_bytes(16));
        $payload = json_encode(['event_id'=>$eventId,'event_type'=>'cart.item_added','event_version'=>1,'user_id'=>$userId,'session_id'=>$sessionId,'cart_id'=>$cartId,'product_id'=>$productId,'variant_id'=>$variantId,'occurred_at'=>gmdate('c')], JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO fashion_event_outbox (event_id,event_type,event_version,aggregate_key,payload,status) VALUES (?,?,?,?,?,?)')->execute([$eventId,'cart.item_added',1,'cart:'.$cartId,$payload,'pending']);
        return $eventId;
    }
}
