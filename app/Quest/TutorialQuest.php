<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Fight\FightService;
use App\Services\Game\GameConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Учебный бой с Деревянным солдатом.
 *
 * Как получить: после ника и города игрок на шаге `intro`;
 * кнопка «В бой!» стартует бой → `tutorial_fight`.
 *
 * Что сделать: победить солдата (стойка → удар → блок). Зелье в туториале
 * недоступно. Поражение: full heal (шаг остаётся / откат на intro в хендлере).
 *
 * Награда (`onboarding.rewards.tutorialWin`): exp + gold → `quest_stats`.
 */
final class TutorialQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly CombatService $combat,
        private readonly FightService $fights,
    ) {}

    public function introText(): string
    {
        return __('onboarding.intro');
    }

    public function start(Character $character): Fight
    {
        return DB::transaction(function () use ($character): Fight {
            $soldier = $this->combat->makeWoodenSoldier();
            $fight = $this->fights->createTutorial($character, $soldier);
            $character->onboarding_step = OnboardingStepEnum::TUTORIAL_FIGHT;
            $character->save();

            return $fight;
        });
    }

    public function onWin(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $reward = $this->reward('tutorialWin');
            $this->characters->addExpGold($character, $reward['exp'], $reward['gold']);
            $character->onboarding_step = OnboardingStepEnum::QUEST_STATS;
            $character->save();

            return $character;
        });
    }

    public function onLose(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $character->current_hp = $this->characters->maxHp($character);
            $character->last_hp_update = now();
            $character->save();

            return $character;
        });
    }

    /**
     * @return array{exp: int, gold: int}
     */
    private function reward(string $key): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        if (! array_key_exists($key, $onboarding['rewards']) || ! is_array($onboarding['rewards'][$key])) {
            throw new RuntimeException("onboarding.rewards.{$key} missing.");
        }

        $row = $onboarding['rewards'][$key];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException("reward {$key}.exp missing.");
        }

        if (! array_key_exists('gold', $row) || ! is_int($row['gold'])) {
            throw new RuntimeException("reward {$key}.gold missing.");
        }

        return [
            'exp' => $row['exp'],
            'gold' => $row['gold'],
        ];
    }
}
