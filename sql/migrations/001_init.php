<?php
/**
 * Migration runner — executed from db.php on startup and by the deploy gate.
 * Creates a migration tracking table and applies pending .sql migrations once.
 */
function runMigrations(PDO $pdo, string $migrationsDir): void
{
    $lockName = 'shop_quan_ao_schema_migrations';
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database migration lock');
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
            id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name varchar(255) NOT NULL,
            applied_at timestamp NOT NULL DEFAULT current_timestamp(),
            UNIQUE KEY name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $check = $pdo->prepare('SELECT 1 FROM _migrations WHERE name = ?');
            $check->execute([$name]);
            if ($check->fetchColumn() !== false) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // These migrations contain plain DDL/DML and no stored procedures.
            $lines = explode("\n", $sql);
            $cleanLines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
            $statements = explode(';', implode("\n", $cleanLines));
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                $pdo->exec($statement);
            }

            $insert = $pdo->prepare('INSERT INTO _migrations (name) VALUES (?)');
            $insert->execute([$name]);
            error_log("Migration applied: $name");
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
