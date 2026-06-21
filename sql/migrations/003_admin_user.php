<?php
/**
 * Migration: Ensure admin user exists.
 * Reads ADMIN_EMAIL / ADMIN_PASSWORD from env.
 * Falls back to dev defaults (with warning log).
 */
function migrateEnsureAdmin(PDO $pdo): void {
    // Check if any admin exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    if ((int)$stmt->fetchColumn() > 0) {
        return; // Admin already exists
    }

    $email = getenv('ADMIN_EMAIL') ?: 'admin@shop.com';
    $password = getenv('ADMIN_PASSWORD') ?: '';

    if ($password === '') {
        // Dev fallback: create with random password + log it
        $password = bin2hex(random_bytes(8)); // 16 char random
        error_log("🔑 Dev admin created — email: $email, password: $password");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'admin', 1)");
    $stmt->execute(['admin', $email, $hash]);
    error_log("✅ Admin user created: $email");
}
