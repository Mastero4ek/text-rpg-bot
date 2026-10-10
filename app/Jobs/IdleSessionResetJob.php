<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Character;
use App\Services\Telegram\IdleSessionService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class IdleSessionResetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $actionAtUnix,
    ) {}

    public function handle(
        IdleSessionService $idle,
        TelegramClient $telegram,
    ): void {
        $character = Character::query()->find($this->tgId);

        if (! $character instanceof Character) {
            return;
        }

        if (! $idle->actionAtMatches($character, $this->actionAtUnix)) {
            return;
        }

        if (! $idle->shouldNotify($character)) {
            return;
        }

        $chatId = $character->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        $idle->notifyIfIdle(
            $character,
            TelegramResponder::forChat($telegram, $chatId),
        );
    }
}
