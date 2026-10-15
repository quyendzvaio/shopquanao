<?php
require dirname(__DIR__) . '/src/bootstrap.php';
/**
 * Optional ops script: ensure at least one admin user exists.
 *
 * Run manually (not part of the migration gate):
 *   php scripts/ensure_admin_user.php
 *
 * Does nothing if an admin already exists. Otherwise creates one from the
 * ADMIN_EMAIL / ADMIN_PASSWORD env vars. If ADMIN_PASSWORD is empty (dev
 * fallback), a random password is generated and logged via error_log().
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

migrateEnsureAdmin($pdo);
