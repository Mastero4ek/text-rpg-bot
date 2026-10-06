<?php

declare(strict_types=1);

namespace App\Quest;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
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
 * Награда (`onboarding.rewards.tutorialQuest`): exp + silver → `quest_stats`.
 */
final class TutorialQuest
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
    ) {}

    public function introText(): string
    {
        return __('onboarding.intro');
    }

    public function start(Character $character): Fight
    {
        return DB::transaction(function () use ($character): Fight {
            $soldier = $this->enemies->makeFromCatalog(
                $this->enemies->tutorialCatalog(),
                $character,
            );
            $fight = $this->fights->createTutorial($character, $soldier);
            $character->onboarding_step = OnboardingStepEnum::TUTORIAL_FIGHT;
            $character->save();

            return $fight;
        });
    }

    public function onWin(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $reward = $this->reward('tutorialQuest');
            $this->characters->addExpSilver($character, $reward['exp'], $reward['silver']);
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
     * @return array{exp: int, silver: int}
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

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException("reward {$key}.silver missing.");
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
        ];
    }
}
