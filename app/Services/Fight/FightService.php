<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\CharacterService;
use App\Support\Enemy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class FightService
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function createHall(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, false, true, FightReturnEnum::Training);
    }

    public function createTraining(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, false, false, FightReturnEnum::Forest);
    }

    public function createTutorial(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, true, false, null);
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

    public function clear(int $tgId): void
    {
        Fight::query()->whereKey($tgId)->delete();
    }

    public function save(Fight $fight): Fight
    {
        $fight->save();

        return $fight;
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

    public function rememberTelegramPanel(
        Fight $fight,
        int $chatId,
        int $statusMessageId,
        string $replyKind,
        ?int $keyboardMessageId,
    ): Fight {
        $fight->tg_chat_id = $chatId;
        $fight->tg_message_id = $statusMessageId;
        $fight->tg_log_message_id = $keyboardMessageId;
        $fight->tg_reply_kind = $replyKind;

        return $this->save($fight);
    }

    public function scheduleTurn(Fight $fight): Fight
    {
        $fight->turn_seq += 1;
        $fight->turn_deadline_at = null;
        $this->save($fight);

        return $fight;
    }

    private function createFight(
        Character $character,
        Enemy $enemy,
        bool $tutorial,
        bool $hall,
        ?FightReturnEnum $returnTo,
    ): Fight {
        $fight = DB::transaction(function () use ($character, $enemy, $tutorial, $hall, $returnTo): Fight {
            Fight::query()->whereKey($character->tg_id)->delete();

            $character = $this->characters->applyRegen($character);
            $maxStamina = $this->characters->maxStamina($character);
            $character->fight_return = $returnTo;
            $character->save();

            $fight = new Fight;
            $fight->tg_id = $character->tg_id;
            $fight->kind = $tutorial ? FightKindEnum::TUTORIAL : FightKindEnum::PVE;
            $fight->tutorial = $tutorial;
            $fight->hall = $hall;
            $fight->return_to = $returnTo;
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
            $fight->last_round = null;
            $fight->turn_seq = 0;
            $fight->turn_deadline_at = null;
            $fight->tg_chat_id = null;
            $fight->tg_message_id = null;
            $fight->tg_log_message_id = null;
            $fight->save();

            return $this->findByTgId($character->tg_id);
        });

        return $this->scheduleTurn($fight);
    }
}
