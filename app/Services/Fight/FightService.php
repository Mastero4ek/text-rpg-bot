<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\FightKindEnum;
use App\Enums\FightStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Support\Game\Enemy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class FightService
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function createTutorial(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, true);
    }

    public function createTraining(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, false);
    }

    public function findByTgId(int $tgId): Fight
    {
        $fight = Fight::query()->find($tgId);

        if ($fight === null) {
            throw (new ModelNotFoundException)->setModel(Fight::class, [$tgId]);
        }

        return $fight;
    }

    public function exists(int $tgId): bool
    {
        return Fight::query()->whereKey($tgId)->exists();
    }

    public function save(Fight $fight): Fight
    {
        $fight->save();

        return $fight;
    }

    public function clear(int $tgId): void
    {
        Fight::query()->whereKey($tgId)->delete();
    }

    public function enemy(Fight $fight): Enemy
    {
        return Enemy::fromArray($fight->enemy);
    }

    /**
     * @return list<string>
     */
    public function log(Fight $fight): array
    {
        return $fight->log;
    }

    private function createFight(Character $character, Enemy $enemy, bool $tutorial): Fight
    {
        return DB::transaction(function () use ($character, $enemy, $tutorial): Fight {
            Fight::query()->whereKey($character->tg_id)->delete();

            $fight = new Fight;
            $fight->tg_id = $character->tg_id;
            $fight->kind = $tutorial ? FightKindEnum::TUTORIAL : FightKindEnum::PVE;
            $fight->tutorial = $tutorial;
            $fight->player_hp = $character->current_hp;
            $fight->player_max_hp = $this->characters->maxHp($character);
            $fight->enemy = $enemy->toArray();
            $fight->step = FightStepEnum::STANCE;
            $fight->player_stance = null;
            $fight->player_attack = null;
            $fight->player_attack_second = null;
            $fight->player_defend = null;
            $fight->player_defend_second = null;
            $fight->use_potion = false;
            $fight->pierce_count = 0;
            $fight->log = [];
            $fight->save();

            return $this->findByTgId($character->tg_id);
        });
    }
}
