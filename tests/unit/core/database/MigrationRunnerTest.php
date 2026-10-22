<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/2026_01_01_a.sql', "-- create\nCREATE TABLE a (id INTEGER);\nINSERT INTO a VALUES (1);");
        file_put_contents($this->dir . '/2026_01_02_b.sql', "CREATE TABLE b (id INTEGER);");
        file_put_contents($this->dir . '/legacy.php', "<?php // ignored");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testAppliesPendingSqlFilesOnceInNameOrder(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $runner = new MigrationRunner($pdo, $this->dir);

        $this->assertSame(['2026_01_01_a.sql', '2026_01_02_b.sql'], $runner->run());
        $this->assertSame([], $runner->run());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM a')->fetchColumn());
        $this->assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn());
    }

    public function testSplitStatementsDropsCommentLinesAndBlanks(): void
    {
        $this->assertSame(
            ['CREATE TABLE x (id INTEGER)', 'INSERT INTO x VALUES (1)'],
            MigrationRunner::splitStatements("-- note\nCREATE TABLE x (id INTEGER);\n\nINSERT INTO x VALUES (1);\n")
        );
    }
}
