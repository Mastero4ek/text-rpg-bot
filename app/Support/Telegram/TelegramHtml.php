<?php

declare(strict_types=1);

namespace App\Support\Telegram;

final class TelegramHtml
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param  list<string>  $values
     */
    public static function escapeJoin(array $values, string $separator): string
    {
        $escaped = [];

        foreach ($values as $value) {
            $escaped[] = self::escape($value);
        }

        return implode($separator, $escaped);
    }
}
