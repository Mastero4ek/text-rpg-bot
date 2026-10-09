<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ResolveFightTurnTimeoutJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $turnSeq,
    ) {}

    public function handle(
        FightRoundService $rounds,
        FightService $fights,
        FightPanelService $panel,
        FightEndService $ends,
        TelegramClient $telegram,
        CityHandler $city,
    ): void {
        if (! $fights->exists($this->tgId)) {
            return;
        }

        $fight = $fights->findByTgId($this->tgId);

        if ($fight->turn_seq !== $this->turnSeq) {
            return;
        }

        if (! $fights->turnTimedOut($fight)) {
            return;
        }

        $player = Character::query()->find($this->tgId);

        if ($player === null) {
            return;
        }

        $outcome = $rounds->runSkipRound($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'continue') {
            $panel->refresh($telegram, $outcome->fight, $outcome->character);

            return;
        }

        if ($outcome->kind === 'win') {
            $result = $ends->finishWin($outcome->character, $outcome->fight);
        } else {
            $result = $ends->finishLose($outcome->character, $outcome->fight);
        }

        $panel->publishEnd(
            $telegram,
            $result,
            $outcome->fight->tg_chat_id,
            $outcome->fight->tg_message_id,
            $outcome->fight,
        );

        if (
            $result->editUi !== FightEndUiEnum::BackToCity
            && $result->editUi !== FightEndUiEnum::MainMenu
        ) {
            return;
        }

        $chatId = $outcome->fight->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        $city->returnAfterFight(TelegramResponder::forChat($telegram, $chatId), $result->character);
    }
}
