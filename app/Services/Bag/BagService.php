<?php

declare(strict_types=1);

namespace App\Services\Bag;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use App\Support\Mf;
use App\Support\Random\RandomSourceContract;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BagService
{
    public function __construct(
        private readonly BagCatalog $catalog,
        private readonly ShopCatalog $shop,
        private readonly GameConfig $config,
        private readonly RandomSourceContract $random,
        private readonly CityQuery $cityQuery,
    ) {}

    public function addPotion(int $tgId, string $catalogId): BagItem
    {
        return DB::transaction(function () use ($tgId, $catalogId): BagItem {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                throw new RuntimeException('Character not found.');
            }

            $potion = $this->catalog->findPotion($catalogId);

            $stack = BagItem::query()
                ->where('tg_id', $tgId)
                ->where('kind', BagKindEnum::POTION->value)
                ->whereNull('backpack_item_id')
                ->where('catalog_id', $potion->catalogId)
                ->where('quantity', '<', $this->potionMaxStack())
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($stack instanceof BagItem) {
                $stack->quantity += 1;
                $stack->save();

                return $stack;
            }

            if ($this->isBagFull($character)) {
                throw new RuntimeException('Bag is full.');
            }

            $row = new BagItem;
            $row->tg_id = $tgId;
            $row->kind = BagKindEnum::POTION;
            $row->catalog_id = $potion->catalogId;
            $row->quantity = 1;
            $row->durability = null;
            $row->backpack_item_id = null;
            $row->created_at = now();
            $row->save();

            return $row;
        });
    }

    public function canAcceptPotion(Character $character, string $catalogId): bool
    {
        $potion = $this->catalog->findPotion($catalogId);

        $hasStackSpace = BagItem::query()
            ->where('tg_id', $character->tg_id)
            ->where('kind', BagKindEnum::POTION->value)
            ->whereNull('backpack_item_id')
            ->where('catalog_id', $potion->catalogId)
            ->where('quantity', '<', $this->potionMaxStack())
            ->exists();

        if ($hasStackSpace) {
            return true;
        }

        return ! $this->isBagFull($character);
    }

    public function consumePotion(int $tgId, ProfileEnum $profile): ActionResult
    {
        if ($profile !== ProfileEnum::HEAL && $profile !== ProfileEnum::STAMINA) {
            throw new RuntimeException('Potion profile must be HEAL or STAMINA.');
        }

        return DB::transaction(function () use ($tgId, $profile): ActionResult {
            $rows = BagItem::query()
                ->where('tg_id', $tgId)
                ->where('kind', BagKindEnum::POTION->value)
                ->whereNull('backpack_item_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $match = null;

            foreach ($rows as $row) {
                if (! $this->catalog->hasPotion($row->catalog_id)) {
                    continue;
                }

                if ($this->catalog->findPotion($row->catalog_id)->profile !== $profile) {
                    continue;
                }

                $match = $row;

                break;
            }

            if ($match === null) {
                return ActionResult::fail(__('combat.no_potion_turn'));
            }

            $potion = $this->catalog->findPotion($match->catalog_id);

            if ($match->quantity > 1) {
                $match->quantity -= 1;
                $match->save();
            } else {
                $match->delete();
            }

            return ActionResult::okWithPotion($this->freshCharacter($tgId), $potion);
        });
    }

    public function potionCount(int $tgId): int
    {
        $total = BagItem::query()
            ->where('tg_id', $tgId)
            ->where('kind', BagKindEnum::POTION->value)
            ->whereNull('backpack_item_id')
            ->sum('quantity');

        return (int) $total;
    }

    public function potionCountByProfile(int $tgId, ProfileEnum $profile): int
    {
        if ($profile !== ProfileEnum::HEAL && $profile !== ProfileEnum::STAMINA) {
            throw new RuntimeException('Potion profile must be HEAL or STAMINA.');
        }

        $count = 0;

        $rows = BagItem::query()
            ->where('tg_id', $tgId)
            ->where('kind', BagKindEnum::POTION->value)
            ->whereNull('backpack_item_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (! $this->catalog->hasPotion($row->catalog_id)) {
                continue;
            }

            if ($this->catalog->findPotion($row->catalog_id)->profile !== $profile) {
                continue;
            }

            $count += $row->quantity;
        }

        return $count;
    }

    public function potionMaxStack(): int
    {
        $bag = $this->bagSettings();

        if (! array_key_exists('potionMaxStack', $bag) || ! is_int($bag['potionMaxStack'])) {
            throw new RuntimeException('settings.bag.potionMaxStack missing.');
        }

        if ($bag['potionMaxStack'] < 1) {
            throw new RuntimeException('settings.bag.potionMaxStack must be >= 1.');
        }

        return $bag['potionMaxStack'];
    }

    /**
     * @return list<string> destroyed gem names
     */
    public function breakSocketedOnLose(Character $character): array
    {
        return DB::transaction(function () use ($character): array {
            $locked = Character::query()
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return [];
            }

            $destroyedNames = [];
            $chance = $this->breakChanceFor($locked);

            $equippedIds = BackpackItem::query()
                ->where('tg_id', $locked->tg_id)
                ->equipped()
                ->pluck('id')
                ->all();

            if ($equippedIds === []) {
                return [];
            }

            $gems = BagItem::query()
                ->where('tg_id', $locked->tg_id)
                ->where('kind', BagKindEnum::GEM->value)
                ->whereIn('backpack_item_id', $equippedIds)
                ->orderBy('id')
                ->get();

            foreach ($gems as $gem) {
                if (! $this->catalog->hasGem($gem->catalog_id)) {
                    continue;
                }

                if ($gem->durability === null) {
                    continue;
                }

                $roll = $this->random->float() * 100.0;

                if ($roll >= $chance) {
                    continue;
                }

                $next = $gem->durability - 1;

                if ($next <= 0) {
                    $destroyedNames[] = $this->catalog->findGem($gem->catalog_id)->name;
                    $gem->delete();

                    continue;
                }

                $gem->durability = $next;
                $gem->save();
            }

            return $destroyedNames;
        });
    }

    public function grantGem(Character $character, string $catalogId, int $qty): Character
    {
        if (! $this->catalog->gemInCatalog($catalogId)) {
            throw new RuntimeException("Unknown gem {$catalogId}");
        }

        if ($qty < 1) {
            throw new RuntimeException('Gem qty must be >= 1.');
        }

        $def = $this->catalog->findGem($catalogId);

        if (! $def->enabled) {
            throw new RuntimeException("Gem {$catalogId} disabled.");
        }

        $character = $this->freshCharacter($character->tg_id);

        if (! $this->canAcceptBagRows($character, $qty)) {
            throw new RuntimeException(__('errors.bag_full'));
        }

        for ($i = 0; $i < $qty; $i++) {
            $this->createLooseGem($character->tg_id, $catalogId, $def->maxDurability);
        }

        return $this->freshCharacter($character->tg_id);
    }

    public function buy(Character $character, string $catalogId): ActionResult
    {
        return DB::transaction(function () use ($character, $catalogId): ActionResult {
            if (! $this->catalog->gemInCatalog($catalogId)) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $gem = $this->catalog->findGem($catalogId);

            if (! $gem->enabled || ! $gem->inShop) {
                return ActionResult::fail(__('errors.gem_not_in_shop'));
            }

            if ($character->city_id === null) {
                return ActionResult::fail(__('errors.no_shop'));
            }

            $city = City::query()->find($character->city_id);

            if (! $city instanceof City || ! $city->has_buyer || ! $this->cityQuery->bagInCityShop($city->id, $catalogId)) {
                return ActionResult::fail(__('errors.gem_not_in_shop'));
            }

            if ($gem->currency === CurrencyEnum::SILVER) {
                $updated = Character::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('silver', '>=', $gem->price)
                    ->decrement('silver', $gem->price);
            } else {
                $updated = Character::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('gold', '>=', $gem->price)
                    ->decrement('gold', $gem->price);
            }

            if ($updated <= 0) {
                if ($gem->currency === CurrencyEnum::SILVER) {
                    return ActionResult::fail(__('errors.not_enough_silver'));
                }

                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $character = $this->freshCharacter($character->tg_id);

            if (! $this->canAcceptBagRows($character, 1)) {
                return ActionResult::fail(__('errors.bag_full'));
            }

            $this->createLooseGem($character->tg_id, $catalogId, $gem->maxDurability);

            return ActionResult::ok($this->freshCharacter($character->tg_id));
        });
    }

    public function discardLoose(Character $character, int $bagItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $bagItemId): ActionResult {
            $row = BagItem::query()
                ->where('id', $bagItemId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->backpack_item_id !== null) {
                return ActionResult::fail(__('errors.gem_socketed'));
            }

            if ($row->kind === BagKindEnum::POTION && $row->quantity > 1) {
                $row->quantity -= 1;
                $row->save();

                return ActionResult::ok($this->freshCharacter($character->tg_id));
            }

            $row->delete();

            return ActionResult::ok($this->freshCharacter($character->tg_id));
        });
    }

    public function freeSocketCount(BackpackItem $item): int
    {
        $slots = $this->gemSlotCount($item);

        if ($slots <= 0) {
            return 0;
        }

        return max(0, $slots - $this->socketedInstances($item)->count());
    }

    public function gemSlotCount(BackpackItem $item): int
    {
        if (! $this->shop->hasItem($item->catalog_id)) {
            return 0;
        }

        $def = $this->shop->findItem($item->catalog_id);

        if ($def->gemSlots === null || $def->gemSlots <= 0) {
            return 0;
        }

        return $def->gemSlots;
    }

    public function socket(Character $character, int $backpackItemId, int $bagItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $backpackItemId, $bagItemId): ActionResult {
            $item = BackpackItem::query()
                ->where('id', $backpackItemId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($item === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($this->freeSocketCount($item) <= 0) {
                return ActionResult::fail(__('errors.no_gem_slots'));
            }

            $gem = BagItem::query()
                ->where('id', $bagItemId)
                ->where('tg_id', $character->tg_id)
                ->where('kind', BagKindEnum::GEM->value)
                ->whereNull('backpack_item_id')
                ->lockForUpdate()
                ->first();

            if ($gem === null) {
                return ActionResult::fail(__('errors.gem_not_in_pouch'));
            }

            if (! $this->catalog->gemInCatalog($gem->catalog_id)) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            if (! $this->catalog->findGem($gem->catalog_id)->enabled) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $gem->backpack_item_id = $item->id;
            $gem->save();

            return ActionResult::okWithItem($this->freshCharacter($character->tg_id), $item);
        });
    }

    public function mfFromSocketed(BackpackItem $item): Mf
    {
        $mf = new Mf(0, 0, 0, 0);

        foreach ($this->socketedInstances($item) as $gem) {
            if ($gem->durability === null || $gem->durability <= 0) {
                continue;
            }

            if (! $this->catalog->hasGem($gem->catalog_id)) {
                continue;
            }

            $mf = $mf->merge($this->catalog->findGem($gem->catalog_id)->mf);
        }

        return $mf;
    }

    /**
     * @return Collection<int, BagItem>
     */
    public function socketedInstances(BackpackItem $item): Collection
    {
        if ($item->relationLoaded('socketedGems')) {
            return $item->getRelation('socketedGems');
        }

        return BagItem::query()
            ->where('backpack_item_id', $item->id)
            ->where('kind', BagKindEnum::GEM->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function socketedGemIds(BackpackItem $item): array
    {
        $ids = [];

        foreach ($this->socketedInstances($item) as $gem) {
            $ids[] = $gem->catalog_id;
        }

        return $ids;
    }

    public function socketedText(BackpackItem $item): string
    {
        $slots = $this->gemSlotCount($item);

        if ($slots <= 0) {
            throw new RuntimeException('Item has no gem slots.');
        }

        $instances = $this->socketedInstances($item);
        $parts = [];

        foreach ($instances as $gem) {
            if ($this->catalog->hasGem($gem->catalog_id)) {
                $def = $this->catalog->findGem($gem->catalog_id);
                $parts[] = __('smith.gem_instance', [
                    'name' => $def->name,
                    'current' => $gem->durability,
                    'max' => $def->maxDurability,
                ]);
            } else {
                $parts[] = $gem->catalog_id;
            }
        }

        $empty = $slots - $instances->count();

        for ($i = 0; $i < $empty; $i++) {
            $parts[] = __('smith.gem_slot_empty');
        }

        return implode(', ', $parts);
    }

    /**
     * @return Collection<int, BagItem>
     */
    public function looseGems(Character $character): Collection
    {
        return BagItem::query()
            ->where('tg_id', $character->tg_id)
            ->where('kind', BagKindEnum::GEM->value)
            ->whereNull('backpack_item_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, BagItem>
     */
    public function loosePotions(Character $character): Collection
    {
        return BagItem::query()
            ->where('tg_id', $character->tg_id)
            ->where('kind', BagKindEnum::POTION->value)
            ->whereNull('backpack_item_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, BagItem>
     */
    public function sellableLooseList(int $tgId): Collection
    {
        return BagItem::query()
            ->where('tg_id', $tgId)
            ->loose()
            ->orderBy('id')
            ->get();
    }

    public function sellCurrency(BagItem $row): CurrencyEnum
    {
        if ($row->kind === BagKindEnum::POTION) {
            return $this->catalog->findPotion($row->catalog_id)->currency;
        }

        return $this->catalog->findGem($row->catalog_id)->currency;
    }

    public function sellLabel(BagItem $row): string
    {
        if ($row->kind === BagKindEnum::POTION) {
            return $this->catalog->findPotion($row->catalog_id)->name;
        }

        return $this->catalog->findGem($row->catalog_id)->name;
    }

    public function sellPayout(BagItem $row): int
    {
        if ($row->kind === BagKindEnum::POTION) {
            $price = $this->catalog->findPotion($row->catalog_id)->price;

            return intdiv($price * $this->sellRatioPermille(), 1000);
        }

        if (! $this->catalog->hasGem($row->catalog_id)) {
            throw new RuntimeException("Catalog gem {$row->catalog_id} missing for sell.");
        }

        $gem = $this->catalog->findGem($row->catalog_id);
        $base = intdiv($gem->price * $this->sellRatioPermille(), 1000);

        if ($base <= 0) {
            return 0;
        }

        if ($row->durability === null || $gem->maxDurability <= 0) {
            return $base;
        }

        $payout = intdiv($base * $row->durability, $gem->maxDurability);

        if ($payout < 1 && $row->durability > 0) {
            return 1;
        }

        return $payout;
    }

    public function sellRemoveOne(BagItem $row): ActionResult
    {
        if ($row->backpack_item_id !== null) {
            return ActionResult::fail(__('errors.gem_socketed'));
        }

        if ($row->kind === BagKindEnum::POTION && $row->quantity > 1) {
            $row->quantity -= 1;
            $row->save();

            return ActionResult::ok($this->freshCharacter($row->tg_id));
        }

        $row->delete();

        return ActionResult::ok($this->freshCharacter($row->tg_id));
    }

    public function bagMaxRows(Character $character): int
    {
        if ($character->bag_max_rows < 1) {
            throw new RuntimeException('Character bag_max_rows must be >= 1.');
        }

        return $character->bag_max_rows;
    }

    public function bagRowCount(Character $character): int
    {
        return BagItem::query()
            ->where('tg_id', $character->tg_id)
            ->whereNull('backpack_item_id')
            ->count();
    }

    public function canAcceptBagRows(Character $character, int $rows): bool
    {
        if ($rows < 1) {
            throw new RuntimeException('Bag accept rows must be >= 1.');
        }

        return ($this->bagRowCount($character) + $rows) <= $this->bagMaxRows($character);
    }

    public function isBagFull(Character $character): bool
    {
        return $this->bagRowCount($character) >= $this->bagMaxRows($character);
    }

    private function createLooseGem(int $tgId, string $catalogId, int $durability): BagItem
    {
        $row = new BagItem;
        $row->tg_id = $tgId;
        $row->kind = BagKindEnum::GEM;
        $row->catalog_id = $catalogId;
        $row->quantity = 1;
        $row->durability = $durability;
        $row->backpack_item_id = null;
        $row->created_at = now();
        $row->save();

        return $row;
    }

    private function sellRatioPermille(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('shop', $settings) || ! is_array($settings['shop'])) {
            throw new RuntimeException('settings.shop missing.');
        }

        $shop = $settings['shop'];

        if (! array_key_exists('sellRatioPermille', $shop) || ! is_int($shop['sellRatioPermille'])) {
            throw new RuntimeException('settings.shop.sellRatioPermille missing.');
        }

        if ($shop['sellRatioPermille'] < 0 || $shop['sellRatioPermille'] > 1000) {
            throw new RuntimeException('settings.shop.sellRatioPermille must be 0..1000.');
        }

        return $shop['sellRatioPermille'];
    }

    private function breakChanceFor(Character $character): int
    {
        $chance = $this->catalog->breakChanceOnLose();

        if ($this->hasActivePremium($character)) {
            $chance -= $this->catalog->premiumBreakChanceReduce();
        }

        if ($chance < 0) {
            return 0;
        }

        return $chance;
    }

    private function hasActivePremium(Character $character): bool
    {
        if (! $character->premium_until instanceof CarbonInterface) {
            return false;
        }

        return $character->premium_until->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    private function bagSettings(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('bag', $settings) || ! is_array($settings['bag'])) {
            throw new RuntimeException('settings.bag missing.');
        }

        return $settings['bag'];
    }

    private function freshCharacter(int $tgId): Character
    {
        $character = Character::query()->find($tgId);

        if (! $character instanceof Character) {
            throw new RuntimeException("Character {$tgId} missing.");
        }

        return $character;
    }
}
