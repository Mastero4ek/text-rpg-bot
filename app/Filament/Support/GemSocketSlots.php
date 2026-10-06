<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Bag\BagKindEnum;
use App\Models\BackpackItem;
use App\Models\BagCatalog;
use App\Services\Bag\BagService;

final class GemSocketSlots
{
    /**
     * @var array<string, array{url: string, name: string, max: int}>
     */
    private static array $gemById = [];

    /**
     * @return list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>
     */
    public static function forBackpackItem(BackpackItem $record): array
    {
        $bag = app(BagService::class);
        $slotCount = $bag->gemSlotCount($record);

        if ($slotCount <= 0) {
            return [];
        }

        $instances = $bag->socketedInstances($record)->values();
        $catalogIds = [];

        foreach ($instances as $instance) {
            $catalogIds[] = $instance->catalog_id;
        }

        self::warm($catalogIds);

        $rows = [];

        for ($index = 0; $index < $slotCount; $index++) {
            $instance = $instances->get($index);

            if ($instance === null) {
                $rows[] = ['kind' => 'empty'];

                continue;
            }

            $catalogId = $instance->catalog_id;
            $tooltip = self::tooltipFor($catalogId);
            $url = '';

            if (array_key_exists($catalogId, self::$gemById)) {
                $url = self::$gemById[$catalogId]['url'];
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

    private static function tooltipFor(string $catalogId): string
    {
        if (array_key_exists($catalogId, self::$gemById)) {
            return self::$gemById[$catalogId]['name'];
        }

        return $catalogId;
    }

    /**
     * @param  list<string>  $catalogIds
     */
    private static function warm(array $catalogIds): void
    {
        $missing = [];

        foreach ($catalogIds as $catalogId) {
            if (! array_key_exists($catalogId, self::$gemById)) {
                $missing[] = $catalogId;
            }
        }

        if ($missing === []) {
            return;
        }

        $gems = BagCatalog::query()
            ->withTrashed()
            ->with('media')
            ->where('kind', BagKindEnum::GEM->value)
            ->whereIn('catalog_id', $missing)
            ->get();

        foreach ($missing as $catalogId) {
            self::$gemById[$catalogId] = [
                'url' => '',
                'name' => $catalogId,
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

            self::$gemById[$gem->catalog_id] = [
                'url' => $relative,
                'name' => $gem->name,
                'max' => $gem->max_durability,
            ];
        }
    }
}
