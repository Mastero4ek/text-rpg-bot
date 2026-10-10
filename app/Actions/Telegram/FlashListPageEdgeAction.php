<?php

declare(strict_types=1);

namespace App\Actions\Telegram;

use App\Jobs\RestoreTelegramListPanelJob;
use App\Support\Telegram\TelegramResponder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

final class FlashListPageEdgeAction
{
    private const int RESTORE_AFTER_SECONDS = 5;

    private const int GENERATION_TTL_SECONDS = 120;

    public static function payloadKey(int|string $chatId, int $messageId, int $generation): string
    {
        return 'tg:list_edge_payload:' . $chatId . ':' . $messageId . ':' . $generation;
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    public function handle(
        TelegramResponder $responder,
        string $flashText,
        string $restoreText,
        array $markup,
    ): void {
        $responder->edit($flashText, $markup);

        if ($responder->callbackHasPhoto()) {
            $editKind = 'caption';
        } else {
            $editKind = 'text';
        }

        $this->scheduleRestore(
            $responder->chatId(),
            $responder->messageId(),
            $restoreText,
            $markup,
            $editKind,
        );
    }

    public function touch(int|string $chatId, int $messageId): void
    {
        $this->nextGeneration($chatId, $messageId);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function scheduleRestore(
        int|string $chatId,
        int $messageId,
        string $restoreText,
        array $markup,
        string $editKind,
    ): void {
        $generation = $this->nextGeneration($chatId, $messageId);

        Cache::put(
            self::payloadKey($chatId, $messageId, $generation),
            [
                'text' => $restoreText,
                'markup' => $markup,
                'edit_kind' => $editKind,
            ],
            now()->addSeconds(self::GENERATION_TTL_SECONDS),
        );

        if (app()->runningUnitTests()) {
            dispatch(new RestoreTelegramListPanelJob(
                $chatId,
                $messageId,
                $restoreText,
                $markup,
                $editKind,
                $generation,
                0,
            ))->delay(now()->addSeconds(self::RESTORE_AFTER_SECONDS));

            return;
        }

        Process::path(base_path())
            ->timeout(self::RESTORE_AFTER_SECONDS + 30)
            ->start([
                PHP_BINARY,
                base_path('artisan'),
                'telegram:restore-list-panel',
                (string) $chatId,
                (string) $messageId,
                (string) $generation,
                (string) self::RESTORE_AFTER_SECONDS,
            ]);
    }

    private function nextGeneration(int|string $chatId, int $messageId): int
    {
        $key = RestoreTelegramListPanelJob::generationKey($chatId, $messageId);
        $generation = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $generation, now()->addSeconds(self::GENERATION_TTL_SECONDS));

        return $generation;
    }
}
