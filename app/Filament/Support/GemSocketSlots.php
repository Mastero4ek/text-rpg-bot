<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Gem;
use App\Models\Inventory;
use App\Services\Gem\GemService;

final class GemSocketSlots
{
    /**
     * @var array<string, array{url: string, name: string, max: int}>
     */
    private static array $gemById = [];

    /**
     * @return list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>
     */
    public static function forInventory(Inventory $record): array
    {
        $gems = app(GemService::class);
        $slotCount = $gems->gemSlotCount($record);

        if ($slotCount <= 0) {
            return [];
        }

        $instances = $gems->socketedInstances($record);
        $gemIds = [];

        foreach ($instances as $instance) {
            $gemIds[] = $instance['gem_id'];
        }

        self::warm($gemIds);

        $rows = [];

        for ($index = 0; $index < $slotCount; $index++) {
            if (! array_key_exists($index, $instances)) {
                $rows[] = ['kind' => 'empty'];

                continue;
            }

            $instance = $instances[$index];
            $gemId = $instance['gem_id'];
            $tooltip = self::tooltipFor($gemId);
            $url = '';

            if (array_key_exists($gemId, self::$gemById)) {
                $url = self::$gemById[$gemId]['url'];
            }

            if ($url === '') {
                $rows[] = [
                    'kind' => 'camera',
                    'tooltip' => $tooltip,
                ];

                continue;
            }

            $rows[] = [
                'kind' => 'image',
                'url' => $url,
                'tooltip' => $tooltip,
            ];
        }

        return $rows;
    }

    private static function relativeUrl(string $url): string
    {
        $appUrl = mb_rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, $appUrl . '/')) {
            return mb_substr($url, mb_strlen($appUrl));
        }

        return $url;
    }

    private static function tooltipFor(string $gemId): string
    {
        if (array_key_exists($gemId, self::$gemById)) {
            return self::$gemById[$gemId]['name'];
        }

        return $gemId;
    }

    /**
     * @param  list<string>  $gemIds
     */
    private static function warm(array $gemIds): void
    {
        $missing = [];

        foreach ($gemIds as $gemId) {
            if (! array_key_exists($gemId, self::$gemById)) {
                $missing[] = $gemId;
            }
        }

        if ($missing === []) {
            return;
        }

        $gems = Gem::query()
            ->withTrashed()
            ->with('media')
            ->whereIn('gem_id', $missing)
            ->get();

        foreach ($missing as $gemId) {
            self::$gemById[$gemId] = [
                'url' => '',
                'name' => $gemId,
                'max' => 0,
            ];
        }

        foreach ($gems as $gem) {
            $url = $gem->getFirstMediaUrl('image');

            if ($url === '') {
                $relative = '';
            } else {
                $relative = self::relativeUrl($url);
            }

            self::$gemById[$gem->gem_id] = [
                'url' => $relative,
                'name' => $gem->name,
                'max' => $gem->max_durability,
            ];
        }
    }
}
