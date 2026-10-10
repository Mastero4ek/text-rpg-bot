<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RestoreTelegramListPanelJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    public function __construct(
        public int|string $chatId,
        public int $messageId,
        public string $text,
        public array $markup,
        public string $editKind,
        public int $generation,
        public int $sleepSeconds,
    ) {}

    public static function generationKey(int|string $chatId, int $messageId): string
    {
        return 'tg:list_edge:' . $chatId . ':' . $messageId;
    }

    public function handle(TelegramClient $telegram): void
    {
        $key = self::generationKey($this->chatId, $this->messageId);

        if ((int) Cache::get($key, 0) !== $this->generation) {
            return;
        }

        try {
            if ($this->editKind === 'caption') {
                $telegram->editMessageCaption(
                    $this->chatId,
                    $this->messageId,
                    $this->text,
                    $this->markup,
                );

                return;
            }

            $telegram->editMessageText(
                $this->chatId,
                $this->messageId,
                $this->text,
                $this->markup,
            );
        } catch (Throwable) {
        }
    }
}
