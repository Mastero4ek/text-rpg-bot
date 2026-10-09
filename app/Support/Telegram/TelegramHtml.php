<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use InvalidArgumentException;
use RuntimeException;

final class TelegramHtml
{
    /**
     * Tags allowed by Telegram Bot API parse_mode=HTML that Filament RichEditor can produce.
     *
     * @see https://core.telegram.org/bots/api#html-style
     */
    private const string EDITOR_ALLOWED_TAGS = '<b><i><u><s><a><code><pre><blockquote><tg-spoiler>';

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

    /**
     * Convert TipTap / Filament RichEditor HTML into Telegram parse_mode=HTML.
     * TipTap wraps blocks in p/br which Telegram rejects.
     */
    public static function fromEditor(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = str_ireplace('&nbsp;', ' ', $text);

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        if (! is_string($text)) {
            throw new RuntimeException('Telegram HTML conversion failed on br tags.');
        }

        $text = preg_replace('/<\/p>\s*<p\b[^>]*>/i', "\n\n", $text);
        if (! is_string($text)) {
            throw new RuntimeException('Telegram HTML conversion failed on paragraph boundaries.');
        }

        $text = preg_replace('/<\/?p\b[^>]*>/i', '', $text);
        if (! is_string($text)) {
            throw new RuntimeException('Telegram HTML conversion failed on paragraph tags.');
        }

        $text = preg_replace('/<\/li>\s*<li\b[^>]*>/i', "\n", $text);
        if (! is_string($text)) {
            throw new RuntimeException('Telegram HTML conversion failed on list items.');
        }

        $text = preg_replace('/<\/?ul\b[^>]*>|<\/?ol\b[^>]*>|<\/?li\b[^>]*>/i', '', $text);
        if (! is_string($text)) {
            throw new RuntimeException('Telegram HTML conversion failed on list tags.');
        }

        $text = str_ireplace(
            ['<strong>', '</strong>', '<em>', '</em>', '<ins>', '</ins>', '<del>', '</del>', '<strike>', '</strike>'],
            ['<b>', '</b>', '<i>', '</i>', '<u>', '</u>', '<s>', '</s>', '<s>', '</s>'],
            $text,
        );

        $text = strip_tags($text, self::EDITOR_ALLOWED_TAGS);
        $text = mb_trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Rich editor HTML produced empty Telegram content.');
        }

        return $text;
    }

    /**
     * Convert Telegram parse_mode=HTML (newlines) into TipTap HTML (p/br).
     * TipTap collapses raw \\n in HTML to nothing.
     */
    public static function toEditor(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = str_replace('\\n', "\n", $text);
        $text = mb_trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Telegram HTML is empty.');
        }

        if (preg_match('/<\s*p\b/i', $text) === 1) {
            return $text;
        }

        $text = str_replace("\n", '<br>', $text);

        return '<p>' . $text . '</p>';
    }

    public static function isBlankEditorHtml(string $html): bool
    {
        if (mb_trim($html) === '') {
            return true;
        }

        $plain = str_ireplace(['<br>', '<br/>', '<br />', '&nbsp;'], ' ', $html);
        $plain = strip_tags($plain);

        return mb_trim($plain) === '';
    }
}
