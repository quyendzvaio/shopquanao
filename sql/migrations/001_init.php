<?php
/**
 * Migration runner — executed from db.php on startup.
 * Creates a migration tracking table and applies any pending .sql migrations.
 */
function runMigrations(PDO $pdo, string $migrationsDir): void {
    // Create migration tracking table
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
        id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name varchar(255) NOT NULL,
        applied_at timestamp NOT NULL DEFAULT current_timestamp(),
        UNIQUE KEY name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Get already-applied migrations
    $stmt = $pdo->query("SELECT name FROM _migrations");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Scan migration files
    $files = glob($migrationsDir . '/*.sql');
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied)) continue;

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') continue;

        try {
            // Remove comment lines then split by semicolon
            $lines = explode("\n", $sql);
            $cleanLines = array_filter($lines, fn($l) => !str_starts_with(trim($l), '--'));
            $sql = implode("\n", $cleanLines);
            
            $statements = explode(';', $sql);
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') continue;
                $pdo->exec($stmt);
            }

            // Record migration
            $ins = $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)");
            $ins->execute([$name]);
            error_log("Migration applied: $name");
        } catch (Exception $e) {
            error_log("Migration FAILED: $name - " . $e->getMessage());
            // Don't block startup for non-critical migration failures
        }
    }
}
