<?php

declare(strict_types=1);

namespace App\Services\Gem;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
use App\Models\Inventory;
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

            if ($locked->gem_ward_charges > 0) {
                $locked->gem_ward_charges -= 1;
                $locked->save();

                return [];
            }

            $destroyedNames = [];
            $chance = $this->breakChanceFor($locked);

            $equipped = Inventory::query()
                ->where('tg_id', $locked->tg_id)
                ->where('is_equipped', true)
                ->orderBy('id')
                ->get();

            foreach ($equipped as $row) {
                $socketed = $this->socketedInstances($row);

                if ($socketed === []) {
                    continue;
                }

                $kept = [];

                foreach ($socketed as $instance) {
                    if (! $this->gems->has($instance['gem_id'])) {
                        continue;
                    }

                    $roll = $this->random->float() * 100.0;

                    if ($roll < $chance) {
                        $next = $instance['durability'] - 1;

                        if ($next <= 0) {
                            $destroyedNames[] = $this->gems->find($instance['gem_id'])->name;

                            continue;
                        }

                        $kept[] = [
                            'gem_id' => $instance['gem_id'],
                            'durability' => $next,
                        ];

                        continue;
                    }

                    $kept[] = $instance;
                }

                $row->socketed_gems = $kept;
                $row->save();
            }

            return $destroyedNames;
        });
    }

    public function buyWard(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $cost = $this->gems->wardGold();

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

            $locked->gem_ward_charges += 1;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    public function grantToPouch(Character $character, string $gemId, int $qty): Character
    {
        if (! $this->gems->inCatalog($gemId)) {
            throw new RuntimeException("Unknown gem {$gemId}");
        }

        if ($qty < 1) {
            throw new RuntimeException('Gem qty must be >= 1.');
        }

        $def = $this->gems->find($gemId);

        if (! $def->enabled) {
            throw new RuntimeException("Gem {$gemId} disabled.");
        }

        $character = $this->freshCharacter($character->tg_id);
        $this->appendInstances($character, $gemId, $def->maxDurability, $qty);

        return $this->freshCharacter($character->tg_id);
    }

    public function buy(Character $character, string $gemId): ActionResult
    {
        return DB::transaction(function () use ($character, $gemId): ActionResult {
            if (! $this->gems->inCatalog($gemId)) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $gem = $this->gems->find($gemId);

            if (! $gem->enabled || ! $gem->inShop) {
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
            $this->appendInstances($character, $gemId, $gem->maxDurability, 1);

            return ActionResult::ok($character);
        });
    }

    public function freeSocketCount(Inventory $row): int
    {
        $slots = $this->gemSlotCount($row);

        if ($slots <= 0) {
            return 0;
        }

        return max(0, $slots - count($this->socketedInstances($row)));
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

        foreach ($this->socketedInstances($row) as $instance) {
            if ($instance['durability'] <= 0) {
                continue;
            }

            if (! $this->gems->has($instance['gem_id'])) {
                continue;
            }

            $mf = $mf->merge($this->gems->find($instance['gem_id'])->mf);
        }

        return $mf;
    }

    /**
     * @return list<array{gem_id: string, durability: int}>
     */
    public function pouch(Character $character): array
    {
        if (! is_array($character->gem_pouch)) {
            return [];
        }

        $out = [];

        foreach ($character->gem_pouch as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! array_key_exists('gem_id', $row) || ! is_string($row['gem_id']) || $row['gem_id'] === '') {
                continue;
            }

            if (! array_key_exists('durability', $row) || ! is_int($row['durability']) || $row['durability'] <= 0) {
                continue;
            }

            $out[] = [
                'gem_id' => $row['gem_id'],
                'durability' => $row['durability'],
            ];
        }

        return $out;
    }

    public function socket(Character $character, int $inventoryRowId, int $pouchIndex): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId, $pouchIndex): ActionResult {
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

            $character = $this->freshCharacter($character->tg_id);
            $pouch = $this->pouch($character);

            if (! array_key_exists($pouchIndex, $pouch)) {
                return ActionResult::fail(__('errors.gem_not_in_pouch'));
            }

            $instance = $pouch[$pouchIndex];

            if (! $this->gems->inCatalog($instance['gem_id'])) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            $def = $this->gems->find($instance['gem_id']);

            if (! $def->enabled) {
                return ActionResult::fail(__('errors.gem_not_found'));
            }

            unset($pouch[$pouchIndex]);
            $character->gem_pouch = array_values($pouch);
            $character->save();

            $socketed = $this->socketedInstances($row);
            $socketed[] = $instance;
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
        $ids = [];

        foreach ($this->socketedInstances($row) as $instance) {
            $ids[] = $instance['gem_id'];
        }

        return $ids;
    }

    /**
     * @return list<array{gem_id: string, durability: int}>
     */
    public function socketedInstances(Inventory $row): array
    {
        if (! is_array($row->socketed_gems)) {
            return [];
        }

        $out = [];

        foreach ($row->socketed_gems as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! array_key_exists('gem_id', $entry) || ! is_string($entry['gem_id']) || $entry['gem_id'] === '') {
                continue;
            }

            if (! array_key_exists('durability', $entry) || ! is_int($entry['durability']) || $entry['durability'] <= 0) {
                continue;
            }

            $out[] = [
                'gem_id' => $entry['gem_id'],
                'durability' => $entry['durability'],
            ];
        }

        return $out;
    }

    public function socketedText(Inventory $row): string
    {
        $instances = $this->socketedInstances($row);
        $slots = $this->gemSlotCount($row);

        if ($slots <= 0) {
            throw new RuntimeException('Item has no gem slots.');
        }

        $parts = [];

        foreach ($instances as $instance) {
            if ($this->gems->has($instance['gem_id'])) {
                $def = $this->gems->find($instance['gem_id']);
                $parts[] = __('smith.gem_instance', [
                    'name' => $def->name,
                    'current' => $instance['durability'],
                    'max' => $def->maxDurability,
                ]);
            } else {
                $parts[] = $instance['gem_id'];
            }
        }

        $empty = $slots - count($instances);

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

            $socketed = $this->socketedInstances($row);

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

            $instance = $socketed[$socketIndex];
            unset($socketed[$socketIndex]);
            $row->socketed_gems = array_values($socketed);
            $row->save();

            $character = $this->freshCharacter($character->tg_id);
            $pouch = $this->pouch($character);
            $pouch[] = $instance;
            $character->gem_pouch = $pouch;
            $character->save();

            $character = $this->freshCharacter($character->tg_id);

            return ActionResult::okWithItem($character, $row);
        });
    }

    private function appendInstances(Character $character, string $gemId, int $durability, int $qty): void
    {
        $pouch = $this->pouch($character);

        for ($i = 0; $i < $qty; $i++) {
            $pouch[] = [
                'gem_id' => $gemId,
                'durability' => $durability,
            ];
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
}
