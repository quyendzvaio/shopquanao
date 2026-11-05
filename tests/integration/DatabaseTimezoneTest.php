<?php

use PHPUnit\Framework\TestCase;

/**
 * The shop runs on Vietnam time: PHP formats dates with Asia/Ho_Chi_Minh and the connection from config/db.php reads
 * the TIMESTAMP columns in the same offset. Before this, PHP and MariaDB were both UTC and every time shown (orders,
 * reviews, admin pages, support tickets in chat) was 7 hours behind.
 */
final class DatabaseTimezoneTest extends TestCase
{
    public function testPhpUsesVietnamTime(): void
    {
        $this->assertSame('Asia/Ho_Chi_Minh', date_default_timezone_get());
    }

    public function testTheSharedConnectionReadsTimestampsInThePhpOffset(): void
    {
        if ((string) (getenv('DB_HOST') ?: '') === '') {
            $this->markTestSkipped('DB_HOST not set; live connection test skipped');
        }
        $pdo = (static function (): PDO {
            require dirname(__DIR__, 2) . '/config/db.php';
            return $pdo;
        })();

        $this->assertSame(date('P'), $pdo->query('SELECT @@session.time_zone')->fetchColumn());
        // NOW() (what a TIMESTAMP DEFAULT stores and reads back) matches PHP's clock in the same zone.
        $dbNow = strtotime((string) $pdo->query('SELECT NOW()')->fetchColumn());
        $this->assertLessThan(120, abs($dbNow - time()));
    }
}
