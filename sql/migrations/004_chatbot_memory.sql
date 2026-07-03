-- ============================================================
-- Migration: Chatbot short-term, slot, and long-term memory
-- ============================================================

CREATE TABLE IF NOT EXISTS chat_session_memory (
    session_id int NOT NULL PRIMARY KEY,
    summary text DEFAULT NULL,
    slots longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    CONSTRAINT chat_session_memory_ibfk_1 FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS user_long_term_memory (
    user_id int NOT NULL PRIMARY KEY,
    preferences longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    stable_facts longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    important_events longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    feedback longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    purchase_history longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    CONSTRAINT user_long_term_memory_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
