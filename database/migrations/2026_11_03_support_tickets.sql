CREATE TABLE IF NOT EXISTS support_tickets (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT(11) NOT NULL,
  order_id INT(11) NULL,
  type VARCHAR(20) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'moi',
  admin_note TEXT NULL,
  chat_session_id INT(11) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_support_tickets_user_status (user_id, status),
  INDEX idx_support_tickets_status_created (status, created_at),
  CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_support_tickets_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
