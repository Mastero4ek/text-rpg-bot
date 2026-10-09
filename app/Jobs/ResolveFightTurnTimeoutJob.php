<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Fight\FightEndResult;
use App\Services\Fight\FightEndService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Fight\FightStatusFormatter;
use App\Support\Telegram\TelegramClient;
use App\Telegram\Keyboards\TelegramKeyboards;
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
        FightStatusFormatter $fightStatus,
        FightEndService $ends,
        TelegramClient $telegram,
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

        $chatId = $outcome->fight->tg_chat_id;
        $messageId = $outcome->fight->tg_message_id;

        if ($outcome->kind === 'continue') {
            if ($chatId !== null && $messageId !== null) {
                $telegram->editMessageText(
                    $chatId,
                    $messageId,
                    $fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
                    TelegramKeyboards::stance(),
                );
            }

            return;
        }

        if ($outcome->kind === 'win') {
            $this->publishEnd(
                $telegram,
                $ends->finishWin($outcome->character, $outcome->fight),
                $chatId,
                $messageId,
            );

            return;
        }

        $this->publishEnd(
            $telegram,
            $ends->finishLose($outcome->character, $outcome->fight),
            $chatId,
            $messageId,
        );
    }

    private function publishEnd(
        TelegramClient $telegram,
        FightEndResult $result,
        ?int $chatId,
        ?int $messageId,
    ): void {
        if ($chatId === null || $messageId === null) {
            return;
        }

        $telegram->editMessageText(
            $chatId,
            $messageId,
            $result->editText,
            TelegramKeyboards::fightEndMarkup($result->editUi, $result->character),
        );

        if ($result->replyText === null || $result->replyUi === FightEndUiEnum::None) {
            return;
        }

        $telegram->sendMessage(
            $chatId,
            $result->replyText,
            TelegramKeyboards::fightEndMarkup($result->replyUi, $result->character),
        );
    }
}
