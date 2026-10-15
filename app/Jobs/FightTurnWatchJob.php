<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Services\Fight\FightEndResult;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Pvp\PvpDuelOutcome;
use App\Services\Pvp\PvpRoundService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FightTurnWatchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $turnSeq,
    ) {}

    public function handle(
        FightService $fights,
        FightRoundService $rounds,
        PvpRoundService $pvpRounds,
        FightEndService $ends,
        FightPanelService $panel,
        TelegramClient $telegram,
        CityHandler $city,
    ): void {
        $fight = Fight::query()->find($this->tgId);

        if (! $fight instanceof Fight) {
            return;
        }

        if ($fight->turn_seq !== $this->turnSeq) {
            return;
        }

        if ($fight->turn_deadline_at === null) {
            return;
        }

        $player = Character::query()->find($this->tgId);

        if (! $player instanceof Character) {
            return;
        }

        $remainingSeconds = $fight->turn_deadline_at->getTimestamp() - now()->getTimestamp();

        if ($remainingSeconds > 0) {
            $panel->refresh($telegram, $fight, $player);
            $fights->dispatchTurnWatch($fight);

            return;
        }

        if ($fight->kind === FightKindEnum::PVP) {
            $this->applyPvpOutcome(
                $pvpRounds->submitSkip($player),
                $ends,
                $panel,
                $telegram,
                $city,
            );

            return;
        }

        $outcome = $rounds->runSkipRound($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'win') {
            $this->publishEnd(
                $ends->finishWin($outcome->character, $outcome->fight),
                $outcome->fight,
                $panel,
                $telegram,
                $city,
            );

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->publishEnd(
                $ends->finishLose($outcome->character, $outcome->fight),
                $outcome->fight,
                $panel,
                $telegram,
                $city,
            );

            return;
        }

        $panel->refresh($telegram, $outcome->fight, $outcome->character);
    }

    private function applyPvpOutcome(
        PvpDuelOutcome $outcome,
        FightEndService $ends,
        FightPanelService $panel,
        TelegramClient $telegram,
        CityHandler $city,
    ): void {
        if ($outcome->kind === 'missing') {
            return;
        }

        if ($outcome->kind === 'waiting') {
            if ($outcome->characterA instanceof Character && $outcome->fightA instanceof Fight) {
                $panel->refresh($telegram, $outcome->fightA, $outcome->characterA);
            }

            return;
        }

        if (
            ! $outcome->characterA instanceof Character
            || ! $outcome->fightA instanceof Fight
            || ! $outcome->characterB instanceof Character
            || ! $outcome->fightB instanceof Fight
        ) {
            return;
        }

        if ($outcome->kind === 'continue') {
            $panel->refresh($telegram, $outcome->fightA, $outcome->characterA);
            $panel->refresh($telegram, $outcome->fightB, $outcome->characterB);

            return;
        }

        $fightA = $outcome->fightA;
        $fightB = $outcome->fightB;
        $charA = $outcome->characterA;
        $charB = $outcome->characterB;

        if ($outcome->kind === 'aborted') {
            $this->publishEnd($ends->finishPvpAbort($charA, $fightA), $fightA, $panel, $telegram, $city);
            $this->publishEnd($ends->finishPvpAbort($charB, $fightB), $fightB, $panel, $telegram, $city);

            return;
        }

        if ($outcome->kind !== 'finished' || $outcome->winnerTgId === null) {
            return;
        }

        if ($outcome->winnerTgId === $charA->tg_id) {
            $this->publishEnd($ends->finishWin($charA, $fightA), $fightA, $panel, $telegram, $city);
            $this->publishEnd($ends->finishLose($charB, $fightB), $fightB, $panel, $telegram, $city);

            return;
        }

        $this->publishEnd($ends->finishWin($charB, $fightB), $fightB, $panel, $telegram, $city);
        $this->publishEnd($ends->finishLose($charA, $fightA), $fightA, $panel, $telegram, $city);
    }

    private function publishEnd(
        FightEndResult $result,
        Fight $fight,
        FightPanelService $panel,
        TelegramClient $telegram,
        CityHandler $city,
    ): void {
        $chatId = $fight->tg_chat_id;

        $panel->publishEnd(
            $telegram,
            $result,
            $chatId,
            $fight->tg_message_id,
            $fight,
        );

        if (
            $result->editUi !== FightEndUiEnum::BackToCity
            && $result->editUi !== FightEndUiEnum::MainMenu
        ) {
            return;
        }

        if ($chatId === null) {
            return;
        }

        $city->returnAfterFight(
            TelegramResponder::forChat($telegram, $chatId),
            $result->character,
        );
    }
}
