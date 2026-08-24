CREATE TABLE IF NOT EXISTS fashion_event_outbox (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(80) NOT NULL UNIQUE,
    event_type VARCHAR(120) NOT NULL,
    event_version INT NOT NULL DEFAULT 1,
    aggregate_key VARCHAR(120) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    last_error VARCHAR(2000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fashion_outbox_pending (status, available_at, id)
);

CREATE TABLE IF NOT EXISTS fashion_consumed_events (
    consumer_name VARCHAR(120) NOT NULL,
    event_id VARCHAR(80) NOT NULL,
    consumed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (consumer_name, event_id)
);

CREATE TABLE IF NOT EXISTS proactive_styling_state (
    user_id BIGINT NOT NULL,
    session_id VARCHAR(120) NOT NULL,
    pending_product_id BIGINT NULL,
    pending_variant_id BIGINT NULL,
    remaining_user_turns INT NOT NULL DEFAULT 0,
    source_event_id VARCHAR(80) NULL,
    eligible TINYINT(1) NOT NULL DEFAULT 0,
    suggested_anchor_product_id BIGINT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, session_id)
);
