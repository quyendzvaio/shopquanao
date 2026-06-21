-- Fix for Docker volumes initialized before the users table existed.
-- Safe to run on the current shop_db without dropping existing data.

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` varchar(20) DEFAULT 'user',
  `status` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `api_token` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `role` varchar(20) DEFAULT 'user',
  ADD COLUMN IF NOT EXISTS `status` tinyint(1) DEFAULT 1;

INSERT INTO `users` (`id`, `username`, `email`, `password`, `created_at`, `role`, `status`)
VALUES
  (3, 'tongvanhiep', 'hiep@gmail.com', '$2y$10$xSMmpMglcrGRjnuZESdvC.KhdKOIF4B4yRTCrnBXhYNhAXIWDEna6', '2025-12-20 18:33:05', 'user', 1)
ON DUPLICATE KEY UPDATE
  `username` = VALUES(`username`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);
