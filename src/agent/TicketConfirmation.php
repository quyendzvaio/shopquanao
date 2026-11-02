<?php

/**
 * Reads the customer's reply to the ticket confirmation question. Only a whole message that is one of
 * the fixed phrases counts: "đồng ý, mà đổi sang size XL" is a new request, not a confirmation.
 */
final class TicketConfirmation
{
    private const YES = ['đồng ý', 'xác nhận', 'ok', 'oke', 'có', 'ừ', 'ừm', 'đúng rồi', 'gửi đi', 'gửi', 'được', 'yes'];
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

        if (in_array($text, self::YES, true)) {
            return 'yes';
        }
        if (in_array($text, self::NO, true)) {
            return 'no';
        }
        return 'other';
    }
}
