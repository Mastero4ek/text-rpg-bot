<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Models\Character;
use App\Services\Gem\GemCatalog;
use App\Support\Game\GemMfText;
use App\Support\Gem\GemDef;

final class GemPouchTable
{
    /**
     * @return list<array{index: int, gem_id: string, name: string, type: string, durability: string, mf: string}>
     */
    public static function rowsFor(Character $character): array
    {
        if (! is_array($character->gem_pouch)) {
            return [];
        }

        $catalog = app(GemCatalog::class);
        $rows = [];

        foreach ($character->gem_pouch as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! array_key_exists('gem_id', $entry) || ! is_string($entry['gem_id']) || $entry['gem_id'] === '') {
                continue;
            }

            if (! array_key_exists('durability', $entry) || ! is_int($entry['durability']) || $entry['durability'] <= 0) {
                continue;
            }

            $gemId = $entry['gem_id'];
            $durability = $entry['durability'];

            if ($catalog->has($gemId)) {
                $rows[] = self::rowFromDef((int) $index, $catalog->find($gemId), $durability);

                continue;
            }

            $rows[] = [
                'index' => (int) $index,
                'gem_id' => $gemId,
                'name' => $gemId,
                'type' => '-',
                'durability' => (string) $durability,
                'mf' => '-',
            ];
        }

        return $rows;
    }

    /**
     * @return array{index: int, gem_id: string, name: string, type: string, durability: string, mf: string}
     */
    private static function rowFromDef(int $index, GemDef $def, int $durability): array
    {
        $typeLabel = $def->type->getLabel();

        if ($typeLabel === '') {
            $typeLabel = $def->type->value;
        }

        return [
            'index' => $index,
            'gem_id' => $def->id,
            'name' => $def->name,
            'type' => $typeLabel,
            'durability' => $durability . '/' . $def->maxDurability,
            'mf' => GemMfText::forDef($def),
        ];
    }
}
