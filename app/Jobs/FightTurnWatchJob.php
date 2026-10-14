<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Services\Fight\FightEndResult;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
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
