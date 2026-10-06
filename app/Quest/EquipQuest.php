<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Квест экипировки (кольчуга).
 *
 * Как получить: после StatsQuest (`quest_equip`), в инвентаре уже `heavy_0`.
 *
 * Что сделать: надеть кольчугу.
 *
 * Награда (`onboarding.rewards.equipQuest`): exp + silver → `quest_shop`.
 */
final class EquipQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly LoadoutService $loadout,
        private readonly ShopCatalog $shop,
    ) {}

    public function finish(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $res = $this->loadout->equipByItemId($character, $this->shop->mailShirtId());

            if (! $res->ok || ! $res->character instanceof Character) {
                return $res;
            }

            $player = $res->character;
            $reward = $this->reward();
            $this->characters->addExpSilver($player, $reward['exp'], $reward['silver']);
            $player->onboarding_step = OnboardingStepEnum::QUEST_SHOP;
            $player->save();

            return ActionResult::ok($player);
        });
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

        if (! array_key_exists('equipQuest', $onboarding['rewards']) || ! is_array($onboarding['rewards']['equipQuest'])) {
            throw new RuntimeException('onboarding.rewards.equipQuest missing.');
        }

        $row = $onboarding['rewards']['equipQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('equipQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('equipQuest.silver missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
        ];
    }
}
