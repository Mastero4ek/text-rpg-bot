<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Jobs\RestoreTelegramListPanelJob;
use App\Support\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

final class TelegramRestoreListPanelCommand extends Command
{
    protected $signature = 'telegram:restore-list-panel {chatId} {messageId} {generation} {sleepSeconds}';

    protected $description = 'Restore list panel text after a page-edge flash.';

    public function handle(TelegramClient $telegram): int
    {
        $sleepSeconds = (int) $this->argument('sleepSeconds');

        if ($sleepSeconds > 0) {
            Sleep::for($sleepSeconds)->seconds();
        }

        $chatIdArg = $this->argument('chatId');
        $messageId = (int) $this->argument('messageId');
        $generation = (int) $this->argument('generation');

        if (is_numeric($chatIdArg)) {
            $chatId = (int) $chatIdArg;
        } else {
            $chatId = (string) $chatIdArg;
        }

        $key = RestoreTelegramListPanelJob::generationKey($chatId, $messageId);

        if ((int) Cache::get($key, 0) !== $generation) {
            return self::SUCCESS;
        }

        $payload = Cache::get(FlashListPageEdgeAction::payloadKey($chatId, $messageId, $generation));

        if (! is_array($payload)
            || ! array_key_exists('text', $payload)
            || ! is_string($payload['text'])
            || ! array_key_exists('markup', $payload)
            || ! is_array($payload['markup'])
            || ! array_key_exists('edit_kind', $payload)
            || ! is_string($payload['edit_kind'])
        ) {
            return self::SUCCESS;
        }

        try {
            if ($payload['edit_kind'] === 'caption') {
                $telegram->editMessageCaption(
                    $chatId,
                    $messageId,
                    $payload['text'],
                    $payload['markup'],
                );

                return self::SUCCESS;
            }

            $telegram->editMessageText(
                $chatId,
                $messageId,
                $payload['text'],
                $payload['markup'],
            );
        } catch (Throwable) {
        }

        return self::SUCCESS;
    }
}
