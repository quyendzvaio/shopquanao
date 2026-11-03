<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatbotMemoryTest extends TestCase
{
    public function testSummaryOmitsTheFreeshipNudgeLedgerSlot(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (9, '', '{\"size\":\"M\"}')");
        $memory = new ChatbotMemory($pdo, 9);
        $memory->rememberSlot('freeship_nudged_fingerprint', 'cart-fingerprint-abc');

        $memory->refreshSummary();

        $row = $pdo->query('SELECT summary, slots FROM chat_session_memory WHERE session_id = 9')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('- size: M', (string) $row['summary']);
        $this->assertStringNotContainsString('freeship_nudged_fingerprint', (string) $row['summary']);
        $this->assertStringNotContainsString('cart-fingerprint-abc', (string) $row['summary']);
        $this->assertStringContainsString('cart-fingerprint-abc', (string) $row['slots'], 'the ledger slot itself is kept');
    }
}
