<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Jobs\ResolveFightTurnTimeoutJob;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Support\Game\Enemy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FightService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
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

    public function rememberTelegramMessage(Fight $fight, int $chatId, int $messageId): Fight
    {
        $fight->tg_chat_id = $chatId;
        $fight->tg_message_id = $messageId;

        return $this->save($fight);
    }

    public function scheduleTurn(Fight $fight): Fight
    {
        $seconds = $this->turnTimeoutSeconds();
        $fight->turn_seq += 1;
        $fight->turn_deadline_at = now()->addSeconds($seconds);
        $this->save($fight);

        dispatch(new ResolveFightTurnTimeoutJob($fight->tg_id, $fight->turn_seq))
            ->delay($fight->turn_deadline_at);

        return $fight;
    }

    public function turnTimedOut(Fight $fight): bool
    {
        if ($fight->turn_deadline_at === null) {
            return false;
        }

        return ! $fight->turn_deadline_at->isFuture();
    }

    private function createFight(Character $character, Enemy $enemy, bool $tutorial): Fight
    {
        return DB::transaction(function () use ($character, $enemy, $tutorial): Fight {
            Fight::query()->whereKey($character->tg_id)->delete();

            $character = $this->characters->applyRegen($character);
            $maxStamina = $this->characters->maxStamina($character);

            $fight = new Fight;
            $fight->tg_id = $character->tg_id;
            $fight->kind = $tutorial ? FightKindEnum::TUTORIAL : FightKindEnum::PVE;
            $fight->tutorial = $tutorial;
            $fight->player_hp = $character->current_hp;
            $fight->player_max_hp = $this->characters->maxHp($character);
            $fight->player_stamina = $this->characters->clampStamina(
                $character->current_stamina,
                $maxStamina,
            );
            $fight->player_max_stamina = $maxStamina;
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
            $fight->turn_seq = 0;
            $fight->turn_deadline_at = null;
            $fight->tg_chat_id = null;
            $fight->tg_message_id = null;
            $fight->save();

            return $this->scheduleTurn($this->findByTgId($character->tg_id));
        });
    }

    private function turnTimeoutSeconds(): int
    {
        $combat = $this->config->combat();

        if (! array_key_exists('turnTimeoutSeconds', $combat) || ! is_int($combat['turnTimeoutSeconds'])) {
            throw new RuntimeException('settings.combat.turnTimeoutSeconds missing.');
        }

        if ($combat['turnTimeoutSeconds'] < 1) {
            throw new RuntimeException('settings.combat.turnTimeoutSeconds must be >= 1.');
        }

        return $combat['turnTimeoutSeconds'];
    }
}
