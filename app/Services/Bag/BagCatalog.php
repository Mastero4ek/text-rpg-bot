<?php

declare(strict_types=1);

namespace App\Services\Gem;

use App\Models\Gem;
use App\Services\Game\GameConfig;
use App\Support\Gem\GemCatalogRow;
use App\Support\Gem\GemDef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class GemCatalog
{
    private const string CACHE_KEY = 'gems.catalog.v1';

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function breakChanceOnLose(): int
    {
        $raw = $this->globals()['breakChanceOnLose'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('settings.gems.breakChanceOnLose must be 0..100.');
        }

        return $raw;
    }

    public function find(string $gemId): GemDef
    {
        if ($this->inCatalog($gemId)) {
            return $this->cachedRows()[$gemId]->def;
        }

        $gem = Gem::query()
            ->withTrashed()
            ->where('gem_id', $gemId)
            ->first();

        if (! $gem instanceof Gem) {
            throw new RuntimeException("Unknown gem {$gemId}");
        }

        return $gem->toGemDef();
    }

    public function has(string $gemId): bool
    {
        if ($this->inCatalog($gemId)) {
            return true;
        }

        return Gem::query()
            ->withTrashed()
            ->where('gem_id', $gemId)
            ->exists();
    }

    public function inCatalog(string $gemId): bool
    {
        return array_key_exists($gemId, $this->cachedRows());
    }

    public function premiumBreakChanceReduce(): int
    {
        $raw = $this->globals()['premiumBreakChanceReduce'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('settings.gems.premiumBreakChanceReduce must be 0..100.');
        }

        return $raw;
    }

    /**
     * @return list<GemDef>
     */
    public function shopGems(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if (! $row->inShop) {
                continue;
            }

            $items[] = $row->def;
        }

        return $items;
    }

    public function unsocketSilver(): int
    {
        $raw = $this->globals()['unsocketSilver'];

        if (! is_int($raw) || $raw < 0) {
            throw new RuntimeException('settings.gems.unsocketSilver must be >= 0.');
        }

        return $raw;
    }

    /**
     * @return array<string, GemCatalogRow>
     */
    private function cachedRows(): array
    {
        /** @var Collection<string, GemCatalogRow> $rows */
        $rows = Cache::remember(self::CACHE_KEY, 3600, function (): Collection {
            $rows = new Collection;

            foreach (
                Gem::query()
                    ->orderBy('sort_order')
                    ->orderBy('gem_id')
                    ->get() as $gem
            ) {
                $rows->put($gem->gem_id, new GemCatalogRow(
                    $gem->toGemDef(),
                    $gem->enabled,
                    $gem->in_shop,
                ));
            }

            return $rows;
        });

        return $rows->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function globals(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('gems', $settings) || ! is_array($settings['gems'])) {
            throw new RuntimeException('settings.gems missing.');
        }

        return $settings['gems'];
    }
}
