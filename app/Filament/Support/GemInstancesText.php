<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\Gem\GemCatalog;

final class GemInstancesText
{
    public static function format(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '-';
        }

        $catalog = app(GemCatalog::class);
        $parts = [];

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! array_key_exists('gem_id', $row) || ! is_string($row['gem_id']) || $row['gem_id'] === '') {
                continue;
            }

            if (! array_key_exists('durability', $row) || ! is_int($row['durability']) || $row['durability'] <= 0) {
                continue;
            }

            if ($catalog->has($row['gem_id'])) {
                $def = $catalog->find($row['gem_id']);
                $parts[] = $def->name . ' (' . $row['durability'] . '/' . $def->maxDurability . ')';
            } else {
                $parts[] = $row['gem_id'] . ' (' . $row['durability'] . ')';
            }
        }

        if ($parts === []) {
            return '-';
        }

        return implode('; ', $parts);
    }
}
