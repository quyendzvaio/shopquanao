<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatMemoryRepositoryTest extends TestCase
{
    public function testSaveSessionUpsertsTheSessionMemoryRow(): void
    {
        $repository = new ChatMemoryRepository(SqliteShop::pdo());

        $this->assertNull($repository->sessionMemory(9));
        $repository->saveSession(9, 'first', '{"size":"M"}');
        $repository->saveSession(9, 'second', '{"size":"L"}');

        $this->assertSame(['summary' => 'second', 'slots' => '{"size":"L"}'], $repository->sessionMemory(9));
        $this->assertNull($repository->sessionMemory(10));
    }

    public function testSlotsAreInsertedThenUpdatedWithoutTouchingTheSummary(): void
    {
        $repository = new ChatMemoryRepository(SqliteShop::pdo());

        $this->assertFalse($repository->slotsJson(4), 'no memory row yet');
        $repository->insertSlots(4, '{"a":1}');
        $this->assertSame('{"a":1}', $repository->slotsJson(4));

        $repository->saveSession(4, 'kept summary', '{"a":1}');
        $repository->updateSlots(4, '{"a":2}');

        $this->assertSame('{"a":2}', $repository->slotsJson(4));
        $this->assertSame(['summary' => 'kept summary', 'slots' => '{"a":2}'], $repository->sessionMemory(4));
    }

    public function testSlotsJsonTellsANullSlotsRowFromAMissingRow(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (5, '', NULL)");
        $repository = new ChatMemoryRepository($pdo);

        $this->assertNull($repository->slotsJson(5));
        $this->assertFalse($repository->slotsJson(6));
    }

    public function testLongTermTableIsCreatedOnlyWhenAskedAndUpserted(): void
    {
        $pdo = SqliteShop::pdo();
        $repository = new ChatMemoryRepository($pdo);

        $repository->ensureTables(false);
        $this->assertFalse($this->tableExists($pdo, 'user_long_term_memory'));
        $repository->ensureTables(true);
        $this->assertTrue($this->tableExists($pdo, 'user_long_term_memory'));

        $this->assertNull($repository->longTerm(3));
        $repository->saveLongTerm(3, ['{"p":1}', '{}', '[]', '[]', '[]']);
        $repository->saveLongTerm(3, ['{"p":2}', '{"s":1}', '[]', '[]', '[]']);

        $this->assertSame(
            ['preferences' => '{"p":2}', 'stable_facts' => '{"s":1}', 'important_events' => '[]', 'feedback' => '[]', 'purchase_history' => '[]'],
            $repository->longTerm(3)
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() === 1;
    }
}
