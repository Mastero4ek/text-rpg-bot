<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

final class BackpackEquipPreviewHtml
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
    public static function format(array $lines): HtmlString
    {
        $parts = [];

        foreach ($lines as $line) {
            if ($line['kind'] === 'note') {
                $parts[] = '<div>' . e((string) $line['text']) . '</div>';

                continue;
            }

            $label = self::statLabel((string) $line['stat']);

            if ($line['kind'] === 'plain') {
                $parts[] = '<div>'
                    . e($label)
                    . ': '
                    . e((string) $line['before'])
                    . ' → '
                    . e((string) $line['after'])
                    . '</div>';

                continue;
            }

            $delta = (int) $line['delta'];

            if ($delta > 0) {
                $deltaClass = 'fi-equip-stat-delta-up';
                $deltaText = '+' . $delta;
            } else {
                $deltaClass = 'fi-equip-stat-delta-down';
                $deltaText = (string) $delta;
            }

            $parts[] = '<div>'
                . e($label)
                . ': '
                . e((string) $line['before'])
                . ' → '
                . e((string) $line['after'])
                . ' (<span class="' . $deltaClass . '">' . e($deltaText) . '</span>)</div>';
        }

        return new HtmlString(
            '<div class="fi-equip-stat-changes">' . implode('', $parts) . '</div>',
        );
    }

    private static function statLabel(string $stat): string
    {
        $key = match ($stat) {
            'max_hp' => 'admin.actions.equip.stat_max_hp',
            'damage' => 'admin.actions.equip.stat_damage',
            'armor' => 'admin.actions.equip.stat_armor',
            'dodge' => 'admin.labels.mf_dodge',
            'anti_dodge' => 'admin.labels.mf_anti_dodge',
            'crit' => 'admin.labels.mf_crit',
            'anti_crit' => 'admin.labels.mf_anti_crit',
            default => throw new RuntimeException('Unknown equip preview stat: ' . $stat),
        };

        $text = __($key);

        if (! is_string($text)) {
            throw new RuntimeException($key . ' must be a string.');
        }

        return $text;
    }
}
