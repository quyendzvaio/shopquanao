ALTER TABLE proactive_styling_state
    ADD COLUMN IF NOT EXISTS state_version BIGINT NOT NULL DEFAULT 0 AFTER source_event_id,
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'not_armed' AFTER eligible,
    ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS retry_count INT NOT NULL DEFAULT 0 AFTER failure_reason,
    ADD COLUMN IF NOT EXISTS last_attempt_at TIMESTAMP NULL AFTER retry_count;
