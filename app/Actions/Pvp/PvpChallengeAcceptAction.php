<?php

declare(strict_types=1);

namespace App\Actions\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Fight;
use App\Models\Pvp\PvpChallenge;
use App\Models\Pvp\PvpDuel;
use App\Services\Fight\FightService;
use App\Services\Pvp\PvpChallengeService;
use App\Services\Pvp\PvpOpponentFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PvpChallengeAcceptAction
{
    public function __construct(
        private readonly FightService $fights,
        private readonly PvpChallengeService $challenges,
        private readonly PvpOpponentFactory $opponents,
    ) {}

    /**
     * @return array{ok: bool, error: ?string, duel: ?PvpDuel, fightA: ?Fight, fightB: ?Fight, characterA: ?Character, characterB: ?Character}
     */
    public function handle(Character $acceptor, int $challengeId): array
    {
        return DB::transaction(function () use ($acceptor, $challengeId): array {
            $lockedAcceptor = Character::query()->whereKey($acceptor->tg_id)->lockForUpdate()->first();

            if (! $lockedAcceptor instanceof Character) {
                throw new RuntimeException('Acceptor missing for PvpChallengeAccept.');
            }

            $challenge = PvpChallenge::query()->whereKey($challengeId)->lockForUpdate()->first();

            if (! $challenge instanceof PvpChallenge || $challenge->status !== PvpChallengeStatusEnum::Open) {
                return $this->fail(__('errors.pvp_challenge_gone'));
            }

            if ($challenge->expires_at->isPast()) {
                $challenge->status = PvpChallengeStatusEnum::Expired;
                $challenge->save();

                return $this->fail(__('errors.pvp_challenge_gone'));
            }

            if ($challenge->challenger_tg_id === $lockedAcceptor->tg_id) {
                return $this->fail(__('errors.pvp_own_challenge'));
            }

            if ($lockedAcceptor->city_id === null || $lockedAcceptor->city_id !== $challenge->city_id) {
                return $this->fail(__('errors.pvp_wrong_city'));
            }

            $city = City::query()->find($challenge->city_id);

            if (! $city instanceof City || ! $city->has_fights_list) {
                return $this->fail(__('errors.city_unavailable'));
            }

            $challenger = Character::query()->whereKey($challenge->challenger_tg_id)->lockForUpdate()->first();

            if (! $challenger instanceof Character) {
                return $this->fail(__('errors.pvp_challenge_gone'));
            }

            if (! $this->challenges->canEnterBoard($lockedAcceptor)) {
                if (! $this->challenges->hasEnoughHp($lockedAcceptor)) {
                    return $this->fail(__('errors.pvp_hp_low'));
                }

                return $this->fail(__('errors.pvp_busy'));
            }

            if (! $this->challenges->canEnterBoard($challenger)) {
                return $this->fail(__('errors.pvp_opponent_busy'));
            }

            if (! $this->challenges->withinLevelBracket($lockedAcceptor, $challenger)) {
                return $this->fail(__('errors.pvp_level_bracket'));
            }

            $duel = new PvpDuel;
            $duel->challenge_id = $challenge->id;
            $duel->a_tg_id = $challenger->tg_id;
            $duel->b_tg_id = $lockedAcceptor->tg_id;
            $duel->round = 1;
            $duel->status = PvpDuelStatusEnum::Active;
            $duel->a_choice = null;
            $duel->b_choice = null;
            $duel->round_seq = 0;
            $duel->mutual_skip_streak = 0;
            $duel->save();

            $enemyForA = $this->opponents->fromCharacter($lockedAcceptor);
            $enemyForB = $this->opponents->fromCharacter($challenger);

            $fightA = $this->fights->createPvp($challenger, $enemyForA, $duel->id);
            $fightB = $this->fights->createPvp($lockedAcceptor, $enemyForB, $duel->id);

            $challenge->status = PvpChallengeStatusEnum::Accepted;
            $challenge->accepted_by_tg_id = $lockedAcceptor->tg_id;
            $challenge->duel_id = $duel->id;
            $challenge->save();

            return [
                'ok' => true,
                'error' => null,
                'duel' => $duel->fresh(),
                'fightA' => $fightA,
                'fightB' => $fightB,
                'characterA' => $challenger->fresh(),
                'characterB' => $lockedAcceptor->fresh(),
            ];
        });
    }

    /**
     * @return array{ok: bool, error: ?string, duel: ?PvpDuel, fightA: ?Fight, fightB: ?Fight, characterA: ?Character, characterB: ?Character}
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'duel' => null,
            'fightA' => null,
            'fightB' => null,
            'characterA' => null,
            'characterB' => null,
        ];
    }
}
