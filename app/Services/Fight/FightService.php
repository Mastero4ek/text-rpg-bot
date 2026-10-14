<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\Fight\FightStepEnum;
use App\Jobs\FightTurnWatchJob;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\GameConfig;
use App\Support\Enemy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FightService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function createBlacksmithQuest(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight(
            $character,
            $enemy,
            FightKindEnum::QUEST_BLACKSMITH,
            false,
            false,
            FightReturnEnum::Forest,
        );
    }

    public function createBruiserQuest(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight(
            $character,
            $enemy,
            FightKindEnum::QUEST_BRUISER,
            false,
            false,
            null,
        );
    }

    public function createHealerQuest(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight(
            $character,
            $enemy,
            FightKindEnum::QUEST_HEALER,
            false,
            false,
            null,
        );
    }

    public function createHall(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, FightKindEnum::PVE, false, true, FightReturnEnum::Training);
    }

    public function createTraining(Character $character, Enemy $enemy): Fight
    {
        return $this->createTrainingIn($character, $enemy, FightReturnEnum::Forest);
    }

    public function createTrainingIn(Character $character, Enemy $enemy, FightReturnEnum $returnTo): Fight
    {
        return $this->createFight($character, $enemy, FightKindEnum::PVE, false, false, $returnTo);
    }

    public function createDailyQuest(
        Character $character,
        Enemy $enemy,
        int $dailyContractId,
        FightReturnEnum $returnTo,
    ): Fight {
        $fight = $this->createFight(
            $character,
            $enemy,
            FightKindEnum::QUEST_DAILY,
            false,
            false,
            $returnTo,
        );
        $fight->daily_contract_id = $dailyContractId;

        return $this->save($fight);
    }

    public function createTutorial(Character $character, Enemy $enemy): Fight
    {
        return $this->createFight($character, $enemy, FightKindEnum::QUEST_OVERSEER, true, false, null);
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
        $fight->turn_deadline_at = now()->addSeconds($this->turnTimeoutSeconds());
        $this->save($fight);
        $this->dispatchTurnWatch($fight);

        return $fight;
    }

    public function dispatchTurnWatch(Fight $fight): void
    {
        if ($fight->turn_deadline_at === null) {
            return;
        }

        $this->dispatchWatchJob(
            $fight->tg_id,
            $fight->turn_seq,
            $this->turnWatchDelayMs($fight),
        );
    }

    public function turnTimeoutSeconds(): int
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

    public function turnTickSeconds(): int
    {
        $combat = $this->config->combat();

        if (! array_key_exists('turnTickSeconds', $combat) || ! is_int($combat['turnTickSeconds'])) {
            throw new RuntimeException('settings.combat.turnTickSeconds missing.');
        }

        if ($combat['turnTickSeconds'] < 1) {
            throw new RuntimeException('settings.combat.turnTickSeconds must be >= 1.');
        }

        return $combat['turnTickSeconds'];
    }

    public function turnTimerDisplaySeconds(Fight $fight): int
    {
        if ($fight->turn_deadline_at === null) {
            return 0;
        }

        $timeout = $this->turnTimeoutSeconds();
        $tick = $this->turnTickSeconds();
        $remaining = $fight->turn_deadline_at->getTimestamp() - now()->getTimestamp();

        if ($remaining <= 0) {
            return 0;
        }

        $elapsed = $timeout - $remaining;

        if ($elapsed < 0) {
            $elapsed = 0;
        }

        $ticksPassed = intdiv($elapsed, $tick);
        $display = $timeout - ($ticksPassed * $tick);

        if ($display < 0) {
            return 0;
        }

        return $display;
    }

    public function turnWatchDelayMs(Fight $fight): int
    {
        if ($fight->turn_deadline_at === null) {
            return 0;
        }

        $timeout = $this->turnTimeoutSeconds();
        $tick = $this->turnTickSeconds();
        $deadlineAt = $fight->turn_deadline_at->getTimestamp();
        $nowAt = now()->getTimestamp();
        $remaining = $deadlineAt - $nowAt;

        if ($remaining <= 0) {
            return 0;
        }

        $elapsed = $timeout - $remaining;

        if ($elapsed < 0) {
            $elapsed = 0;
        }

        $ticksPassed = intdiv($elapsed, $tick);
        $startedAt = $deadlineAt - $timeout;
        $nextTickAt = $startedAt + (($ticksPassed + 1) * $tick);

        if ($nextTickAt > $deadlineAt) {
            $nextTickAt = $deadlineAt;
        }

        $delaySeconds = $nextTickAt - $nowAt;

        if ($delaySeconds < 0) {
            return 0;
        }

        return $delaySeconds * 1000;
    }

    /**
     * @param  list<string>  $lines
     */
    public function appendLogLines(Fight $fight, array $lines): Fight
    {
        if (empty($lines)) {
            return $fight;
        }

        $combined = $fight->log;
        $header = __('combat.log_round', [
            'round' => max(1, $fight->turn_seq),
        ]);

        if (! in_array($header, $combined, true)) {
            if (! empty($combined)) {
                $combined[] = '';
            }

            $combined[] = $header;
        }

        foreach ($lines as $line) {
            $combined[] = $line;
        }

        $fight->log = $combined;

        return $fight;
    }

    private function dispatchWatchJob(int $tgId, int $turnSeq, int $delayMs): void
    {
        dispatch(new FightTurnWatchJob($tgId, $turnSeq))
            ->onConnection($this->watchQueueConnection())
            ->delay(now()->addMilliseconds(max(0, $delayMs)));
    }

    private function queueSupportsDelay(string $driver): bool
    {
        if ($driver === 'redis') {
            return true;
        }

        if ($driver === 'database') {
            return true;
        }

        if ($driver === 'beanstalkd') {
            return true;
        }

        return $driver === 'sqs';
    }

    private function watchQueueConnection(): string
    {
        $driver = config('queue.default');

        if (is_string($driver) && $this->queueSupportsDelay($driver)) {
            return $driver;
        }

        // composer dev: QUEUE_CONNECTION=sync + queue:listen database
        return 'database';
    }

    private function createFight(
        Character $character,
        Enemy $enemy,
        FightKindEnum $kind,
        bool $tutorial,
        bool $hall,
        ?FightReturnEnum $returnTo,
    ): Fight {
        $fight = DB::transaction(function () use ($character, $enemy, $kind, $tutorial, $hall, $returnTo): Fight {
            Fight::query()->whereKey($character->tg_id)->delete();

            $character = $this->characters->applyRegen($character);
            $maxStamina = $this->characters->maxStamina($character);
            $character->fight_return = $returnTo;
            $character->save();

            $fight = new Fight;
            $fight->tg_id = $character->tg_id;
            $fight->kind = $kind;
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
