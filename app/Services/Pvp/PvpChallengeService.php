<?php

declare(strict_types=1);

namespace App\Services\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Character\Character;
use App\Models\Pvp\PvpChallenge;
use App\Services\Character\CharacterService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use Illuminate\Support\Collection;

final class PvpChallengeService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly FightService $fights,
        private readonly GameConfig $config,
    ) {}

    public function levelBracket(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('levelBracket', $pvp) || ! is_int($pvp['levelBracket'])) {
            return 2;
        }

        return $pvp['levelBracket'];
    }

    public function minHpPercent(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('minHpPercent', $pvp) || ! is_int($pvp['minHpPercent'])) {
            return 50;
        }

        return $pvp['minHpPercent'];
    }

    public function challengeTtlSeconds(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('challengeTtlSeconds', $pvp) || ! is_int($pvp['challengeTtlSeconds'])) {
            return 3600;
        }

        return $pvp['challengeTtlSeconds'];
    }

    public function pointsPerWin(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('pointsPerWin', $pvp) || ! is_int($pvp['pointsPerWin'])) {
            return 10;
        }

        return $pvp['pointsPerWin'];
    }

    public function pointsPerLose(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('pointsPerLose', $pvp) || ! is_int($pvp['pointsPerLose'])) {
            return 10;
        }

        return $pvp['pointsPerLose'];
    }

    public function mutualSkipAbortRounds(): int
    {
        $pvp = $this->config->pvp();

        if (! array_key_exists('mutualSkipAbortRounds', $pvp) || ! is_int($pvp['mutualSkipAbortRounds'])) {
            return 3;
        }

        if ($pvp['mutualSkipAbortRounds'] < 1) {
            return 3;
        }

        return $pvp['mutualSkipAbortRounds'];
    }

    public function hasEnoughHp(Character $character): bool
    {
        $character = $this->characters->applyRegen($character);
        $maxHp = $this->characters->maxHp($character);

        if ($maxHp <= 0) {
            return false;
        }

        return (int) floor(($character->current_hp * 100) / $maxHp) >= $this->minHpPercent();
    }

    public function withinLevelBracket(Character $a, Character $b): bool
    {
        return abs($a->level - $b->level) <= $this->levelBracket();
    }

    public function canEnterBoard(Character $character): bool
    {
        if ($this->fights->exists($character->tg_id)) {
            return false;
        }

        return $this->hasEnoughHp($character);
    }

    public function openChallengeFor(Character $character): ?PvpChallenge
    {
        return PvpChallenge::query()
            ->where('challenger_tg_id', $character->tg_id)
            ->where('status', PvpChallengeStatusEnum::Open)
            ->first();
    }

    /**
     * @return Collection<int, PvpChallenge>
     */
    public function openInCity(int $cityId): Collection
    {
        return PvpChallenge::query()
            ->with('challenger')
            ->where('city_id', $cityId)
            ->where('status', PvpChallengeStatusEnum::Open)
            ->where('expires_at', '>', now())->oldest()
            ->get();
    }
}
