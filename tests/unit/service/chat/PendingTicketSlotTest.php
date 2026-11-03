<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A drafted support ticket waits in a chat-session slot; isLive says whether it can still be confirmed. */
final class PendingTicketSlotTest extends TestCase
{
    private function stored(int $userId, int $expiresAt): string
    {
        return json_encode(['type' => 'khac', 'summary' => 'x', 'user_id' => $userId, 'expires_at' => $expiresAt]);
    }

    public function testLiveDraftOfTheSameUser(): void
    {
        $this->assertTrue(PendingTicketSlot::isLive($this->stored(7, 1900), 7, 1000));
    }

    public function testExpiredDraft(): void
    {
        $this->assertFalse(PendingTicketSlot::isLive($this->stored(7, 1000), 7, 1000), 'expires_at == now is expired');
        $this->assertFalse(PendingTicketSlot::isLive($this->stored(7, 999), 7, 1000));
    }

    public function testOtherUsersDraft(): void
    {
        $this->assertFalse(PendingTicketSlot::isLive($this->stored(8, 1900), 7, 1000));
    }

    public function testGuestOrInvalidUser(): void
    {
        $this->assertFalse(PendingTicketSlot::isLive($this->stored(7, 1900), null, 1000));
        $this->assertFalse(PendingTicketSlot::isLive($this->stored(0, 1900), 0, 1000));
    }

    public function testCorruptEmptyOrNonStringStorage(): void
    {
        $this->assertFalse(PendingTicketSlot::isLive('{not json', 7, 1000));
        $this->assertFalse(PendingTicketSlot::isLive('', 7, 1000));
        $this->assertFalse(PendingTicketSlot::isLive(null, 7, 1000));
        $this->assertFalse(PendingTicketSlot::isLive(['user_id' => 7], 7, 1000));
        $this->assertFalse(PendingTicketSlot::isLive('"text"', 7, 1000));
        $this->assertFalse(PendingTicketSlot::isLive('{"user_id":7,"expires_at":"2000"}', 7, 1000), 'expires_at must be an int');
    }
}
