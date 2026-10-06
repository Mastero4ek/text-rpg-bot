<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Квест распределения статов.
 *
 * Как получить: победа в TutorialQuest → `quest_stats`.
 *
 * Что сделать: потратить все стартовые `stat_points`, затем сдать квест.
 *
 * Награда (`onboarding.rewards.statsQuest`): exp + кольчуга `heavy_0` → `quest_equip`.
 */
final class StatsQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly ShopCatalog $shop,
    ) {}

    public function text(Character $character): string
    {
        return __('onboarding.stats_quest', [
            'city' => $character->location,
            'points' => $character->stat_points,
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }

    public function finish(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            if ($character->stat_points > 0) {
                return ActionResult::fail(__('errors.spend_all_points'));
            }

            if (! $this->backpack->owns($character->tg_id, $this->shop->mailShirtId())) {
                $mailShirtId = $this->shop->mailShirtId();

                if (! $this->backpack->canAcceptItem($character)) {
                    return ActionResult::fail(__('errors.inventory_full'));
                }

                $this->backpack->addItem($character->tg_id, $mailShirtId);
            }

            $reward = $this->reward();
            $this->characters->addExpSilver($character, $reward['exp'], $reward['silver']);
            $character->onboarding_step = OnboardingStepEnum::QUEST_EQUIP;
            $character->save();

            return ActionResult::ok($character);
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

        if (! array_key_exists('statsQuest', $onboarding['rewards']) || ! is_array($onboarding['rewards']['statsQuest'])) {
            throw new RuntimeException('onboarding.rewards.statsQuest missing.');
        }

        $row = $onboarding['rewards']['statsQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('statsQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('statsQuest.silver missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
        ];
    }
}
