# Use Case 2 — proactive cart styling

Cart addition remains transactional outbox → Redis Streams → idempotent consumer → pending anchor. The state machine counts only USER messages. After two suitable turns, the consumer invokes the same shared styling tool; provider/extraction/normalization/search failures are silent and do not mark the anchor as suggested.

Existing safeguards remain: support contexts suppress recommendations, latest anchor wins, and an anchor is recorded as suggested only after real shop products are shown. Product cards still come exclusively from Product Search.
