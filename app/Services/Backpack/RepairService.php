<?php

declare(strict_types=1);

namespace App\Services\Backpack;

use App\Enums\Equipment\RepairEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RepairService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return Collection<int, BackpackItem>
     */
    public function damagedList(int $tgId): Collection
    {
        return $this->damagedListForTier($tgId, RepairEnum::NORMAL);
    }

    /**
     * @return Collection<int, BackpackItem>
     */
    public function damagedVipList(int $tgId): Collection
    {
        return $this->damagedListForTier($tgId, RepairEnum::VIP);
    }

    public function repair(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->max_durability === null || $row->durability === null) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($row->durability >= $row->max_durability) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            if (! $this->shop->hasItem($row->catalog_id)) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $def->repairable) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($def->repairTier === RepairEnum::VIP) {
                return ActionResult::fail(__('errors.needs_vip_smith'));
            }

            $cost = $this->repairCost($row);

            if ($cost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $updated = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('silver', '>=', $cost)
                ->decrement('silver', $cost);

            if ($updated <= 0) {
                return ActionResult::fail(__('errors.not_enough_silver'));
            }

            $oldCap = $this->characters->maxHp($character);

            $row->durability = $row->max_durability;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function repairVip(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->max_durability === null || $row->durability === null) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($row->durability >= $row->max_durability) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            if (! $this->shop->hasItem($row->catalog_id)) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $def->repairable) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($def->repairTier !== RepairEnum::VIP) {
                return ActionResult::fail(__('errors.vip_smith_only_vip'));
            }

            $silverCost = $this->repairVipSilverCost($row);

            if ($silverCost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $locked = Character::query()
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            $goldPass = 0;

            if (! $this->characters->hasActivePremium($locked)) {
                $goldPass = $this->vipGoldPass();
            }

            if ($goldPass > 0 && $locked->gold < $goldPass) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            if ($locked->silver < $silverCost) {
                return ActionResult::fail(__('errors.not_enough_silver'));
            }

            $locked->silver -= $silverCost;

            if ($goldPass > 0) {
                $locked->gold -= $goldPass;
            }

            $locked->save();

            $oldCap = $this->characters->maxHp($locked);

            $row->durability = $row->max_durability;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function repairVipGoldPass(): int
    {
        return $this->vipGoldPass();
    }

    public function repairVipSilverCost(BackpackItem $row): int
    {
        return $this->repairCost($row) * $this->vipSilverMultiplier();
    }

    public function repairAll(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $damaged = $this->damagedList($character->tg_id);

            if ($damaged->isEmpty()) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $goldCost = $this->repairAllGoldCost($character);

            if ($goldCost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $updated = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('gold', '>=', $goldCost)
                ->decrement('gold', $goldCost);

            if ($updated <= 0) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $oldCap = $this->characters->maxHp($character);

            foreach ($damaged as $row) {
                if ($row->max_durability === null) {
                    continue;
                }

                $row->durability = $row->max_durability;
                $row->save();
            }

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function repairAllGoldCost(Character $character): int
    {
        $silverTotal = 0;

        foreach ($this->damagedList($character->tg_id) as $row) {
            $silverTotal += $this->repairCost($row);
        }

        if ($silverTotal <= 0) {
            return 0;
        }

        $gold = intdiv($silverTotal, $this->goldRepairAllDivisor());

        if ($gold < 1) {
            return 1;
        }

        return $gold;
    }

    public function repairCost(BackpackItem $row): int
    {
        if ($row->max_durability === null || $row->durability === null) {
            throw new RuntimeException('Item has no durability.');
        }

        $missing = $row->max_durability - $row->durability;

        if ($missing <= 0) {
            return 0;
        }

        $def = $this->shop->findItem($row->catalog_id);
        $perPoint = $this->repairSilverPerMissingPoint();

        if ($def->reqLevel === null) {
            $reqLevel = 0;
        } else {
            $reqLevel = $def->reqLevel;
        }

        return $missing * $perPoint * ($reqLevel + 1);
    }

    /**
     * @return Collection<int, BackpackItem>
     */
    private function damagedListForTier(int $tgId, RepairEnum $tier): Collection
    {
        $rows = BackpackItem::query()
            ->where('tg_id', $tgId)
            ->orderBy('id')
            ->get();

        $damaged = new Collection;

        foreach ($rows as $row) {
            if ($row->max_durability === null || $row->durability === null) {
                continue;
            }

            if ($row->durability >= $row->max_durability) {
                continue;
            }

            if (! $this->shop->hasItem($row->catalog_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $def->repairable) {
                continue;
            }

            if ($def->repairTier !== $tier) {
                continue;
            }

            $damaged->push($row);
        }

        return $damaged;
    }

    private function goldRepairAllDivisor(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('repair', $settings) || ! is_array($settings['repair'])) {
            throw new RuntimeException('settings.repair missing.');
        }

        $repair = $settings['repair'];

        if (! array_key_exists('goldRepairAllDivisor', $repair) || ! is_int($repair['goldRepairAllDivisor'])) {
            throw new RuntimeException('settings.repair.goldRepairAllDivisor missing.');
        }

        if ($repair['goldRepairAllDivisor'] < 1) {
            throw new RuntimeException('settings.repair.goldRepairAllDivisor must be >= 1.');
        }

        return $repair['goldRepairAllDivisor'];
    }

    private function vipGoldPass(): int
    {
        $vip = $this->vipRepairConfig();

        if (! array_key_exists('goldPass', $vip) || ! is_int($vip['goldPass'])) {
            throw new RuntimeException('settings.vipRepair.goldPass missing.');
        }

        if ($vip['goldPass'] < 0) {
            throw new RuntimeException('settings.vipRepair.goldPass must be >= 0.');
        }

        return $vip['goldPass'];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function vipRepairConfig(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('vipRepair', $settings) || ! is_array($settings['vipRepair'])) {
            throw new RuntimeException('settings.vipRepair missing.');
        }

        return $settings['vipRepair'];
    }

    private function vipSilverMultiplier(): int
    {
        $vip = $this->vipRepairConfig();

        if (! array_key_exists('silverMultiplier', $vip) || ! is_int($vip['silverMultiplier'])) {
            throw new RuntimeException('settings.vipRepair.silverMultiplier missing.');
        }

        if ($vip['silverMultiplier'] < 1) {
            throw new RuntimeException('settings.vipRepair.silverMultiplier must be >= 1.');
        }

        return $vip['silverMultiplier'];
    }

    private function repairSilverPerMissingPoint(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('repair', $settings) || ! is_array($settings['repair'])) {
            throw new RuntimeException('settings.repair missing.');
        }

        $repair = $settings['repair'];

        if (! array_key_exists('silverPerMissingPoint', $repair) || ! is_int($repair['silverPerMissingPoint'])) {
            throw new RuntimeException('settings.repair.silverPerMissingPoint missing.');
        }

        if ($repair['silverPerMissingPoint'] < 1) {
            throw new RuntimeException('settings.repair.silverPerMissingPoint must be >= 1.');
        }

        return $repair['silverPerMissingPoint'];
    }
}
