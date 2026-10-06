<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

final class BackpackEquipPreviewHtml
{
    /**
     * @param  list<array{
     *     kind: 'note'|'plain'|'delta',
     *     text?: string,
     *     label?: string,
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

            if ($line['kind'] === 'plain') {
                $parts[] = '<div>'
                    . e((string) $line['label'])
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
                . e((string) $line['label'])
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
}
