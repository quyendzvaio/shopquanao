-- UC1/UC2 provider styling and the cart-event pipeline were removed
-- (spec 2026-10-15). Drop only the tables those migrations created.
DROP TABLE IF EXISTS fashion_provider_product_mapping;
DROP TABLE IF EXISTS proactive_styling_state;
DROP TABLE IF EXISTS fashion_consumed_events;
DROP TABLE IF EXISTS fashion_event_outbox;
