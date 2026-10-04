<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Services\Inventory\GemService;
use App\Services\Inventory\InventoryService;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopService;
use App\Support\Game\ActionResult;
use App\Support\Game\EquipmentDef;
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
 * Награда (`onboarding.rewards.shopQuest`): exp + silver, level = graduateLevel,
 * full heal → `done`.
 */
final class ShopQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly InventoryService $inventory,
        private readonly GemService $gems,
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

            if (! $this->inventory->owns($player->tg_id, $itemId)) {
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

            if (! $this->inventory->owns($player->tg_id, $itemId)) {
                $this->inventory->addItem($player->tg_id, $itemId);
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
        $eq = $this->inventory->equipByItemId($player, $itemId);

        if (! $eq->ok || ! $eq->character instanceof Character) {
            return $eq;
        }

        $player = $eq->character;
        $reward = $this->reward();
        $this->characters->addExpSilver($player, $reward['exp'], $reward['silver']);
        $player->level = $this->graduateLevel();
        $player->current_hp = $this->characters->maxHp($player);
        $player->last_hp_update = now();
        $player->onboarding_step = OnboardingStepEnum::DONE;
        $player->save();
        $player = $this->gems->grantToPouch($player, $this->starterGemId(), 1);

        return ActionResult::ok($player);
    }

    private function starterGemId(): string
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('starterGemId', $onboarding) || ! is_string($onboarding['starterGemId'])) {
            throw new RuntimeException('onboarding.starterGemId missing.');
        }

        if ($onboarding['starterGemId'] === '') {
            throw new RuntimeException('onboarding.starterGemId empty.');
        }

        return $onboarding['starterGemId'];
    }

    private function graduateLevel(): int
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('graduateLevel', $onboarding) || ! is_int($onboarding['graduateLevel'])) {
            throw new RuntimeException('onboarding.graduateLevel missing.');
        }

        return $onboarding['graduateLevel'];
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
