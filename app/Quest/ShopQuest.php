<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\ActionResult;
use App\Support\Equipment\EquipmentDef;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Квест магазина новичка (учебное оружие).
 *
 * Как получить: после EquipQuest → `quest_shop`.
 *
 * Что сделать: купить учебное оружие или забрать дубину у Тренера и экипировать.
 * Зелье квест не завершает.
 *
 * Награда (`onboarding.rewards.shopQuest`): exp + silver, full heal → `done`.
 */
final class ShopQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly LoadoutService $loadout,
        private readonly ShopCatalog $shop,
        private readonly ShopService $shopService,
    ) {}

    public function finishWithPurchase(Character $character, string $itemId): ActionResult
    {
        return DB::transaction(function () use ($character, $itemId): ActionResult {
            $check = $this->noviceWeaponOrFail($itemId);

            if ($check instanceof ActionResult) {
                return $check;
            }

            $player = $character;

            if (! $this->backpack->owns($player->tg_id, $itemId)) {
                $buy = $this->shopService->buyWeapon($player->tg_id, $itemId);

                if (! $buy->ok || ! $buy->character instanceof Character) {
                    return $buy;
                }

                $player = $buy->character;
            }

            return $this->equipAndGraduate($player, $itemId);
        });
    }

    public function finishWithTrainerClub(Character $character, string $itemId): ActionResult
    {
        return DB::transaction(function () use ($character, $itemId): ActionResult {
            $check = $this->noviceWeaponOrFail($itemId);

            if ($check instanceof ActionResult) {
                return $check;
            }

            if ($check->itemId !== $this->shop->freeTrainerItemId()) {
                return ActionResult::fail(__('errors.trainer_club_only'));
            }

            $player = $character;

            if (! $this->backpack->owns($player->tg_id, $itemId)) {
                if ($this->backpack->isFull($player)) {
                    return ActionResult::fail(__('errors.inventory_full'));
                }

                $this->backpack->addItem($player->tg_id, $itemId);
            }

            return $this->equipAndGraduate($player, $itemId);
        });
    }

    public function buyPotion(Character $character): ActionResult
    {
        return $this->shopService->buyPotion($character->tg_id);
    }

    private function noviceWeaponOrFail(string $itemId): ActionResult|EquipmentDef
    {
        foreach ($this->shop->noviceWeapons() as $weapon) {
            if ($weapon->itemId === $itemId) {
                return $weapon;
            }
        }

        return ActionResult::fail(__('errors.pick_train_weapon'));
    }

    private function equipAndGraduate(Character $player, string $itemId): ActionResult
    {
        $eq = $this->loadout->equipByItemId($player, $itemId);

        if (! $eq->ok || ! $eq->character instanceof Character) {
            return $eq;
        }

        $player = $eq->character;
        $reward = $this->reward();
        $this->characters->addExpSilver($player, $reward['exp'], $reward['silver']);
        $player->current_hp = $this->characters->maxHp($player);
        $player->last_hp_update = now();
        $player->progress_step = ProgressStepEnum::DONE;
        $player->onboarding_skipped = false;
        $player->save();

        return ActionResult::ok($player);
    }

    /**
     * @return array{exp: int, silver: int}
     */
    private function reward(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        if (! array_key_exists('shopQuest', $onboarding['rewards']) || ! is_array($onboarding['rewards']['shopQuest'])) {
            throw new RuntimeException('onboarding.rewards.shopQuest missing.');
        }

        $row = $onboarding['rewards']['shopQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('shopQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('shopQuest.silver missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
        ];
    }
}
