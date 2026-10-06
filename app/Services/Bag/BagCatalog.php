<?php

declare(strict_types=1);

namespace App\Services\Bag;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Models\BagCatalog as BagCatalogModel;
use App\Services\Game\GameConfig;
use App\Support\Bag\PotionDef;
use App\Support\Gem\GemDef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class BagCatalog
{
    private const string CACHE_KEY = 'bag.catalog.v1';

    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function breakChanceOnLose(): int
    {
        $raw = $this->gemGlobals()['breakChanceOnLose'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('settings.gems.breakChanceOnLose must be 0..100.');
        }

        return $raw;
    }

    public function premiumBreakChanceReduce(): int
    {
        $raw = $this->gemGlobals()['premiumBreakChanceReduce'];

        if (! is_int($raw) || $raw < 0 || $raw > 100) {
            throw new RuntimeException('settings.gems.premiumBreakChanceReduce must be 0..100.');
        }

        return $raw;
    }

    public function findGem(string $catalogId): GemDef
    {
        $model = $this->findModel($catalogId);

        if (! $model instanceof BagCatalogModel) {
            throw new RuntimeException("Unknown gem {$catalogId}");
        }

        return $model->toGemDef();
    }

    public function hasGem(string $catalogId): bool
    {
        $model = $this->findModel($catalogId);

        return $model instanceof BagCatalogModel && $model->kind === BagKindEnum::GEM;
    }

    public function gemInCatalog(string $catalogId): bool
    {
        foreach ($this->cachedRows() as $model) {
            if ($model->catalog_id === $catalogId && $model->kind === BagKindEnum::GEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<GemDef>
     */
    public function shopGems(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $model) {
            if ($model->kind !== BagKindEnum::GEM) {
                continue;
            }

            if (! $model->enabled || ! $model->in_shop) {
                continue;
            }

            $items[] = $model->toGemDef();
        }

        return $items;
    }

    public function findPotion(string $catalogId): PotionDef
    {
        $model = $this->findModel($catalogId);

        if (! $model instanceof BagCatalogModel || $model->kind !== BagKindEnum::POTION) {
            throw new RuntimeException("Unknown potion {$catalogId}");
        }

        return $model->toPotionDef();
    }

    public function hasPotion(string $catalogId): bool
    {
        $model = $this->findModel($catalogId);

        return $model instanceof BagCatalogModel && $model->kind === BagKindEnum::POTION;
    }

    public function shopPotionId(): string
    {
        return $this->shopPotion(ProfileEnum::HEAL)->catalogId;
    }

    public function shopStaminaPotionId(): string
    {
        return $this->shopPotion(ProfileEnum::STAMINA)->catalogId;
    }

    public function potionPrice(): int
    {
        return $this->shopPotion(ProfileEnum::HEAL)->price;
    }

    public function staminaPotionPrice(): int
    {
        return $this->shopPotion(ProfileEnum::STAMINA)->price;
    }

    public function potionHeal(): int
    {
        return $this->shopPotion(ProfileEnum::HEAL)->effectValue;
    }

    public function potionStaminaHeal(): int
    {
        return $this->shopPotion(ProfileEnum::STAMINA)->effectValue;
    }

    private function shopPotion(ProfileEnum $profile): PotionDef
    {
        if ($profile !== ProfileEnum::HEAL && $profile !== ProfileEnum::STAMINA) {
            throw new RuntimeException('Shop potion profile must be HEAL or STAMINA.');
        }

        foreach ($this->cachedRows() as $model) {
            if ($model->kind !== BagKindEnum::POTION) {
                continue;
            }

            if (! $model->enabled || ! $model->in_shop) {
                continue;
            }

            if ($model->profile !== $profile) {
                continue;
            }

            return $model->toPotionDef();
        }

        throw new RuntimeException('Shop potion missing in bag catalog for ' . $profile->value . '.');
    }

    private function findModel(string $catalogId): ?BagCatalogModel
    {
        foreach ($this->cachedRows() as $model) {
            if ($model->catalog_id === $catalogId) {
                return $model;
            }
        }

        $model = BagCatalogModel::query()
            ->withTrashed()
            ->where('catalog_id', $catalogId)
            ->first();

        if ($model instanceof BagCatalogModel) {
            return $model;
        }

        return null;
    }

    /**
     * @return Collection<string, BagCatalogModel>
     */
    private function cachedRows(): Collection
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof Collection) {
            $first = $cached->first();

            if ($first instanceof BagCatalogModel || $cached->isEmpty()) {
                /** @var Collection<string, BagCatalogModel> $cached */
                return $cached;
            }
        }

        Cache::forget(self::CACHE_KEY);

        /** @var Collection<string, BagCatalogModel> $rows */
        $rows = Cache::remember(self::CACHE_KEY, 3600, function (): Collection {
            $rows = new Collection;

            foreach (
                BagCatalogModel::query()
                    ->orderBy('sort_order')
                    ->orderBy('catalog_id')
                    ->get() as $model
            ) {
                $rows->put($model->catalog_id, $model);
            }

            return $rows;
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function gemGlobals(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('gems', $settings) || ! is_array($settings['gems'])) {
            throw new RuntimeException('settings.gems missing.');
        }

        return $settings['gems'];
    }
}
