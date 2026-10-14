<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use RuntimeException;

final class EquipPreviewText
{
    /**
     * @param  list<array{
     *     kind: 'note'|'plain'|'delta',
     *     text?: string,
     *     stat?: string,
     *     before?: string,
     *     after?: string,
     *     delta?: int
     * }>  $lines
     */
    public static function format(array $lines): string
    {
        $parts = [];

        foreach ($lines as $line) {
            if ($line['kind'] === 'note') {
                $parts[] = TelegramHtml::escape((string) $line['text']);

                continue;
            }

            $label = self::statLabel((string) $line['stat']);

            if ($line['kind'] === 'plain') {
                $parts[] = TelegramHtml::escape($label)
                    . ' '
                    . TelegramHtml::escape((string) $line['before'])
                    . ' → '
                    . TelegramHtml::escape((string) $line['after']);

                continue;
            }

            $delta = (int) $line['delta'];

            if ($delta > 0) {
                $deltaText = '+' . $delta;
            } else {
                $deltaText = (string) $delta;
            }

            $parts[] = TelegramHtml::escape($label)
                . ' '
                . TelegramHtml::escape((string) $line['before'])
                . ' → '
                . TelegramHtml::escape((string) $line['after'])
                . ' (<b>'
                . TelegramHtml::escape($deltaText)
                . '</b>)';
        }

        return implode("\n", $parts);
    }

    private static function statLabel(string $stat): string
    {
        $key = 'menu.stat_' . $stat;
        $text = __($key);

        if (! is_string($text) || $text === $key) {
            throw new RuntimeException('Unknown equip preview stat: ' . $stat);
        }

        return $text;
    }
}
