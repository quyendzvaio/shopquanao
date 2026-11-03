<?php

/**
 * Reads the customer's reply to the ticket confirmation question. Only a whole message that is one of
 * the fixed phrases counts: "đồng ý, mà đổi sang size XL" is a new request, not a confirmation. A leading
 * "dạ" and trailing politeness particles ("ạ", "nhé", "bạn"...) around a phrase are tolerated.
 */
final class TicketConfirmation
{
    private const YES = ['đồng ý', 'xác nhận', 'ok', 'oke', 'có', 'ừ', 'ừm', 'đúng rồi', 'gửi đi', 'gửi', 'được', 'yes', 'vâng', 'dạ', 'dạ vâng', 'okay'];
    private const PARTICLES = ['ạ', 'nhé', 'nhe', 'nha', 'nhá', 'nhen', 'luôn', 'đi', 'bạn', 'shop', 'em'];
    private const NO = ['thôi', 'không', 'khỏi', 'huỷ', 'hủy', 'thôi khỏi', 'không gửi', 'không cần'];

    /** @return 'yes'|'no'|'other' */
    public static function classify(string $message): string
    {
        $text = mb_strtolower($message);
        if (class_exists('Normalizer')) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        $text = preg_replace('/^[\s.,!?…~]+|[\s.,!?…~]+$/u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $answer = self::match($text);
        if ($answer !== 'other') {
            return $answer;
        }

        // Tolerate "dạ " in front and politeness particles at the end, but only around a whole fixed phrase.
        $stripped = preg_replace('/^dạ[\s.,]+/u', '', $text) ?? $text;
        $particles = '(?:' . implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), self::PARTICLES)) . ')';
        do {
            $before = $stripped;
            $stripped = preg_replace('/(?:^|[\s.,!?…~]+)' . $particles . '$/u', '', $stripped) ?? $stripped;
            $stripped = preg_replace('/^[\s.,!?…~]+|[\s.,!?…~]+$/u', '', $stripped) ?? $stripped;
        } while ($stripped !== $before);

        return self::match($stripped);
    }

    /** @return 'yes'|'no'|'other' */
    private static function match(string $text): string
    {
        if (in_array($text, self::YES, true)) {
            return 'yes';
        }
        if (in_array($text, self::NO, true)) {
            return 'no';
        }
        return 'other';
    }
}
