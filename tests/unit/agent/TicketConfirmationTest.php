<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The fixed yes/no phrases that confirm or drop a drafted support ticket; anything else is a normal message. */
final class TicketConfirmationTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function yesPhrases(): array
    {
        $phrases = ['đồng ý', 'Đồng ý!', 'xác nhận', 'ok', 'oke', 'có', 'ừ', 'ừm', 'đúng rồi', 'gửi đi', 'gửi', 'được', 'yes', '  OK ... ', 'đồng   ý~'];
        return array_combine($phrases, array_map(static fn (string $p): array => [$p], $phrases));
    }

    /** @return array<string,array{string}> */
    public static function noPhrases(): array
    {
        $phrases = ['thôi', 'không', 'khỏi', 'huỷ', 'hủy', 'thôi khỏi', 'không gửi', 'không cần', 'Thôi.'];
        return array_combine($phrases, array_map(static fn (string $p): array => [$p], $phrases));
    }

    /** @return array<string,array{string}> */
    public static function otherPhrases(): array
    {
        $phrases = ['đồng ý, mà đổi sang size XL luôn', 'có áo khác không', 'ok vậy còn đơn 13', '', '   ', '!!!'];
        return array_combine($phrases, array_map(static fn (string $p): array => [$p], $phrases));
    }

    #[DataProvider('yesPhrases')]
    public function testYes(string $message): void
    {
        $this->assertSame('yes', TicketConfirmation::classify($message));
    }

    #[DataProvider('noPhrases')]
    public function testNo(string $message): void
    {
        $this->assertSame('no', TicketConfirmation::classify($message));
    }

    #[DataProvider('otherPhrases')]
    public function testOther(string $message): void
    {
        $this->assertSame('other', TicketConfirmation::classify($message));
    }
}
