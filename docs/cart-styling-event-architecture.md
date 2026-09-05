# Cart styling event architecture

Redis was already a required service in this project and no other broker existed, so proactive styling uses Redis Streams rather than introducing another messaging system.

The pending user-visible styling state is also armed synchronously in the same
database transaction as the cart mutation. This prevents a stopped publisher or
consumer from silently disabling UC2. Redis delivery is idempotent for the same
`event_id`, so a delayed consumer cannot reset an already-decremented turn count.

```text
POST /api/cart
  → CartService transaction: cart write + fashion_event_outbox
  → publisher worker → Redis Stream fashion:events → status=published
  → consumer group proactive-styling
  → validate + deduplicate event_id + persist pending anchor
  → POST /api/chatbot user turns
  → two-turn counter → suitability gate → styling tool when eligible
```

Delivery is at least once. The consumer's `(consumer_name,event_id)` primary key provides idempotence; the implementation makes no exactly-once claim. New cart events replace pending state, so the latest anchor wins. Provider failures, missing mappings, suppression, and zero shop results do not mark the anchor as shown.

Workers are `fashion-outbox-publisher` and `fashion-event-consumer` in Docker Compose. Run:

```bash
docker compose exec -T app php scripts/smoke_cart_event_pipeline.php
```

The smoke creates a temporary authenticated user, calls the real HTTP cart endpoint, verifies outbox publication and consumer state, then removes only its own temporary records.
