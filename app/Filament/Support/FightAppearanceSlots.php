<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Character\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Models\Pvp\PvpDuel;

final class FightAppearanceSlots
{
    /**
     * @var array<string, array{url: string, name: string}>
     */
    private static array $enemyByCatalogId = [];

    /**
     * @return list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>
     */
    public static function forDuel(PvpDuel $duel): array
    {
        return [
            self::characterSlot($duel->sideA, self::sideTooltip($duel->sideA, $duel->a_tg_id)),
            self::characterSlot($duel->sideB, self::sideTooltip($duel->sideB, $duel->b_tg_id)),
        ];
    }

    /**
     * @return list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>
     */
    public static function forFight(Fight $fight): array
    {
        return [
            self::characterSlot($fight->character, self::characterTooltip($fight)),
            self::enemySlot($fight),
        ];
    }

    /**
     * @return array{kind: 'image'|'camera', url?: string, tooltip?: string}
     */
    private static function characterSlot(?Character $character, string $tooltip): array
    {
        if (! $character instanceof Character) {
            return [
                'kind' => 'camera',
                'tooltip' => $tooltip,
            ];
        }

        $mediaUrl = $character->getFirstMediaUrl('image');

        if ($mediaUrl !== '') {
            return [
                'kind' => 'image',
                'url' => self::relativeUrl($mediaUrl),
                'tooltip' => $tooltip,
            ];
        }

        $skinUrl = CharacterSkinArt::url($character->skin);

        if (is_string($skinUrl) && $skinUrl !== '') {
            return [
                'kind' => 'image',
                'url' => $skinUrl,
                'tooltip' => $tooltip,
            ];
        }

        return [
            'kind' => 'camera',
            'tooltip' => $tooltip,
        ];
    }

    /**
     * @return array{kind: 'image'|'camera', url?: string, tooltip?: string}
     */
    private static function enemySlot(Fight $fight): array
    {
        $enemy = $fight->enemy;
        $tooltip = '-';

        if (array_key_exists('name', $enemy) && is_string($enemy['name']) && $enemy['name'] !== '') {
            $tooltip = $enemy['name'];
        }

        if (! array_key_exists('catalogId', $enemy) || ! is_string($enemy['catalogId']) || $enemy['catalogId'] === '') {
            return [
                'kind' => 'camera',
                'tooltip' => $tooltip,
            ];
        }

        $catalogId = $enemy['catalogId'];

        if (str_starts_with($catalogId, 'pvp:')) {
            $tgId = (int) mb_substr($catalogId, 4);

            if ($tgId < 1) {
                return [
                    'kind' => 'camera',
                    'tooltip' => $tooltip,
                ];
            }

            $opponent = Character::query()->with('media')->find($tgId);

            return self::characterSlot($opponent, $tooltip);
        }

        self::warmEnemy([$catalogId]);

        if (! array_key_exists($catalogId, self::$enemyByCatalogId)) {
            return [
                'kind' => 'camera',
                'tooltip' => $tooltip,
            ];
        }

        $row = self::$enemyByCatalogId[$catalogId];

        if ($row['url'] === '') {
            return [
                'kind' => 'camera',
                'tooltip' => $tooltip,
            ];
        }

        return [
            'kind' => 'image',
            'url' => $row['url'],
            'tooltip' => $tooltip,
        ];
    }

    private static function characterTooltip(Fight $fight): string
    {
        return self::sideTooltip($fight->character, $fight->tg_id);
    }

    private static function sideTooltip(?Character $character, int $tgId): string
    {
        $username = $character?->username;

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $tgId;
    }

    private static function relativeUrl(string $url): string
    {
        $appUrl = mb_rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, $appUrl . '/')) {
            return mb_substr($url, mb_strlen($appUrl));
        }

        return $url;
    }

    /**
     * @param  list<string>  $catalogIds
     */
    private static function warmEnemy(array $catalogIds): void
    {
        $missing = [];

        foreach ($catalogIds as $catalogId) {
            if (! array_key_exists($catalogId, self::$enemyByCatalogId)) {
                $missing[] = $catalogId;
            }
        }

        if ($missing === []) {
            return;
        }

        $enemies = EnemyCatalog::query()
            ->withTrashed()
            ->with('media')
            ->whereIn('catalog_id', $missing)
            ->get();

        foreach ($missing as $catalogId) {
            self::$enemyByCatalogId[$catalogId] = [
                'url' => '',
                'name' => $catalogId,
            ];
        }

        foreach ($enemies as $enemy) {
            $url = $enemy->getFirstMediaUrl('image');

            if ($url === '') {
                $relative = '';
            } else {
                $relative = self::relativeUrl($url);
            }

            self::$enemyByCatalogId[$enemy->catalog_id] = [
                'url' => $relative,
                'name' => $enemy->name,
            ];
        }
    }
}
