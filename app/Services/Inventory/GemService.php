<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Shop\GemCatalog;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ActionResult;
use App\Support\Game\Mf;
use App\Support\Random\RandomSourceContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GemService
{
    public function __construct(
        private readonly GemCatalog $gems,
        private readonly ShopCatalog $shop,
        private readonly RandomSourceContract $random,
    ) {}

    /**
     * @return list<string> broken gem names
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

            if ($locked->gem_insurance_charges > 0) {
                $locked->gem_insurance_charges -= 1;
                $locked->save();

                return [];
            }

            $brokenNames = [];
            $chance = $this->breakChanceFor($locked);

            $equipped = Inventory::query()
                ->where('tg_id', $locked->tg_id)
                ->where('is_equipped', true)
                ->orderBy('id')
                ->get();

            foreach ($equipped as $row) {
                $socketed = $this->socketedGemIds($row);

                if ($socketed === []) {
                    continue;
                }

                $kept = [];

                foreach ($socketed as $gemId) {
                    if (! $this->gems->has($gemId)) {
                        continue;
                    }

                    $roll = $this->random->float() * 100.0;

                    if ($roll < $chance) {
                        $brokenNames[] = $this->gems->find($gemId)->name;

                        continue;
                    }

                    $kept[] = $gemId;
                }

                $row->socketed_gems = $kept;
                $row->save();
            }

            return $brokenNames;
        });
    }

    public function buyInsurance(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $cost = $this->gems->insuranceGold();

            $updated = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('gold', '>=', $cost)
                ->decrement('gold', $cost);

            if ($updated <= 0) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $locked = Character::query()
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            $locked->gem_insurance_charges += 1;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    public function grantToPouch(Character $character, string $gemId, int $qty): Character
    {
        if (! $this->gems->has($gemId)) {
            throw new RuntimeException("Unknown gem {$gemId}");
        }

        if ($qty < 1) {
            throw new RuntimeException('Gem qty must be >= 1.');
        }

        $character = $this->freshCharacter($character->tg_id);
        $this->addToPouch($character, $gemId, $qty);

        return $this->freshCharacter($character->tg_id);
    }

    public function buy(Character $character, string $gemId): ActionResult
    {
        return DB::transaction(function () use ($character, $gemId): ActionResult {
            if (! $this->gems->has($gemId)) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $gem = $this->gems->find($gemId);

            if (! $gem->inShop) {
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
            $this->addToPouch($character, $gemId, 1);

            return ActionResult::ok($character);
        });
    }

    public function freeSocketCount(Inventory $row): int
    {
        $slots = $this->gemSlotCount($row);

        if ($slots <= 0) {
            return 0;
        }

        return max(0, $slots - count($this->socketedGemIds($row)));
    }

    public function gemSlotCount(Inventory $row): int
    {
        if (! $this->shop->hasItem($row->item_id)) {
            return 0;
        }

        $def = $this->shop->findItem($row->item_id);

        if ($def->itemType === TypeEnum::JEWELRY || $def->itemType === TypeEnum::POTION) {
            return 0;
        }

        if ($def->gemSlots === null || $def->gemSlots <= 0) {
            return 0;
        }

        return $def->gemSlots;
    }

    public function mfFromSocketed(Inventory $row): Mf
    {
        $mf = new Mf(0, 0, 0, 0);

        foreach ($this->socketedGemIds($row) as $gemId) {
            if (! $this->gems->has($gemId)) {
                continue;
            }

            $mf = $mf->merge($this->gems->find($gemId)->mf);
        }

        return $mf;
    }

    /**
     * @return array<string, int> gemId => qty
     */
    public function pouch(Character $character): array
    {
        if (! is_array($character->gem_pouch)) {
            return [];
        }

        $out = [];

        foreach ($character->gem_pouch as $gemId => $qty) {
            if ($gemId === '' || $qty <= 0) {
                continue;
            }

            $out[$gemId] = $qty;
        }

        return $out;
    }

    public function socket(Character $character, int $inventoryRowId, string $gemId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId, $gemId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($this->freeSocketCount($row) <= 0) {
                return ActionResult::fail(__('errors.no_gem_slots'));
            }

            if (! $this->gems->has($gemId)) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $character = $this->freshCharacter($character->tg_id);

            if (! $this->takeFromPouch($character, $gemId)) {
                return ActionResult::fail(__('errors.gem_not_in_pouch'));
            }

            $socketed = $this->socketedGemIds($row);
            $socketed[] = $gemId;
            $row->socketed_gems = $socketed;
            $row->save();

            $character = $this->freshCharacter($character->tg_id);

            return ActionResult::okWithItem($character, $row);
        });
    }

    /**
     * @return list<string>
     */
    public function socketedGemIds(Inventory $row): array
    {
        if (! is_array($row->socketed_gems)) {
            return [];
        }

        $ids = [];

        foreach ($row->socketed_gems as $gemId) {
            if ($gemId === '') {
                continue;
            }

            $ids[] = $gemId;
        }

        return $ids;
    }

    public function socketedText(Inventory $row): string
    {
        $ids = $this->socketedGemIds($row);
        $slots = $this->gemSlotCount($row);

        if ($slots <= 0) {
            throw new RuntimeException('Item has no gem slots.');
        }

        $parts = [];

        foreach ($ids as $gemId) {
            if ($this->gems->has($gemId)) {
                $parts[] = $this->gems->find($gemId)->name;
            } else {
                $parts[] = $gemId;
            }
        }

        $empty = $slots - count($ids);

        for ($i = 0; $i < $empty; $i++) {
            $parts[] = __('smith.gem_slot_empty');
        }

        return implode(', ', $parts);
    }

    public function unsocket(Character $character, int $inventoryRowId, int $socketIndex): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId, $socketIndex): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            $socketed = $this->socketedGemIds($row);

            if (! array_key_exists($socketIndex, $socketed)) {
                return ActionResult::fail(__('errors.gem_socket_empty'));
            }

            $cost = $this->gems->unsocketSilver();

            if ($cost > 0) {
                $updated = Character::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('silver', '>=', $cost)
                    ->decrement('silver', $cost);

                if ($updated <= 0) {
                    return ActionResult::fail(__('errors.not_enough_silver'));
                }
            }

            $gemId = $socketed[$socketIndex];
            unset($socketed[$socketIndex]);
            $row->socketed_gems = array_values($socketed);
            $row->save();

            $character = $this->freshCharacter($character->tg_id);

            if ($this->gems->has($gemId)) {
                $this->addToPouch($character, $gemId, 1);
                $character = $this->freshCharacter($character->tg_id);
            }

            return ActionResult::okWithItem($character, $row);
        });
    }

    private function addToPouch(Character $character, string $gemId, int $qty): void
    {
        $pouch = $this->pouch($character);

        if (array_key_exists($gemId, $pouch)) {
            $pouch[$gemId] += $qty;
        } else {
            $pouch[$gemId] = $qty;
        }

        $character->gem_pouch = $pouch;
        $character->save();
    }

    private function breakChanceFor(Character $character): int
    {
        $chance = $this->gems->breakChanceOnLose();

        if ($this->hasActivePremium($character)) {
            $chance -= $this->gems->premiumBreakChanceReduce();
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

    private function freshCharacter(int $tgId): Character
    {
        $character = Character::query()->find($tgId);

        if (! $character instanceof Character) {
            throw new RuntimeException("Character {$tgId} missing.");
        }

        return $character;
    }

    private function takeFromPouch(Character $character, string $gemId): bool
    {
        $pouch = $this->pouch($character);

        if (! array_key_exists($gemId, $pouch) || $pouch[$gemId] <= 0) {
            return false;
        }

        $pouch[$gemId] -= 1;

        if ($pouch[$gemId] <= 0) {
            unset($pouch[$gemId]);
        }

        $character->gem_pouch = $pouch;
        $character->save();

        return true;
    }
}
