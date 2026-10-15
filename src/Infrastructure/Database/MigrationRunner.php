<?php

/**
 * Applies pending *.sql migrations exactly once, tracked by file basename in
 * `_migrations`. Called only from scripts/run_database_migrations.php so the
 * deploy gate (not web requests) owns schema changes. A MariaDB advisory lock
 * serialises concurrent runners.
 */
final class MigrationRunner
{
    private const LOCK_NAME = 'shop_quan_ao_schema_migrations';

    public function __construct(private PDO $pdo, private string $migrationsDir)
    {
    }

    /** @return list<string> basenames applied by this run */
    public function run(): array
    {
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($isMysql) {
            $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
            $lock->execute([self::LOCK_NAME]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('Could not acquire the database migration lock');
            }
        }

        try {
            $this->pdo->exec($isMysql
                ? 'CREATE TABLE IF NOT EXISTS _migrations (
                    id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name varchar(255) NOT NULL,
                    applied_at timestamp NOT NULL DEFAULT current_timestamp(),
                    UNIQUE KEY name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                : 'CREATE TABLE IF NOT EXISTS _migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )');

            $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
            sort($files);
            $check = $this->pdo->prepare('SELECT 1 FROM _migrations WHERE name = ?');
            $insert = $this->pdo->prepare('INSERT INTO _migrations (name) VALUES (?)');
            $applied = [];
            foreach ($files as $file) {
                $name = basename($file);
                $check->execute([$name]);
                if ($check->fetchColumn() !== false) {
                    continue;
                }
                foreach (self::splitStatements((string)file_get_contents($file)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert->execute([$name]);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            if ($isMysql) {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
            }
        }
    }

    /**
     * Migrations contain plain DDL/DML without stored procedures, so splitting
     * on ';' after dropping full-line comments is sufficient.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", $sql),
            fn(string $line): bool => !str_starts_with(trim($line), '--')
        );
        $statements = array_map('trim', explode(';', implode("\n", $lines)));
        return array_values(array_filter($statements, fn(string $s): bool => $s !== ''));
    }
}
