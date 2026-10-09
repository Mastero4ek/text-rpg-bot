<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Sleep;
use RuntimeException;

final class FightPanelService
{
    public function __construct(
        private readonly FightService $fights,
        private readonly FightStatusFormatter $fightStatus,
    ) {}

    public function open(
        TelegramResponder $responder,
        Fight $fight,
        Character $player,
        ?string $imagePath,
    ): Fight {
        $caption = $this->fightStatus->panelCaption($fight, $player);
        $markup = TelegramKeyboards::fightInlineForStep($fight);
        $replyKind = TelegramKeyboards::fightMarkupKind($fight);

        if ($imagePath !== null) {
            try {
                $statusId = $this->replyPhotoWithRetry($responder, $imagePath, $caption, $markup);
            } catch (RuntimeException) {
                $statusId = $this->replyTextWithRetry($responder, $caption, $markup);
            }
        } else {
            $statusId = $this->replyTextWithRetry($responder, $caption, $markup);
        }

        return $this->fights->rememberTelegramPanel(
            $fight,
            (int) $responder->chatId(),
            $statusId,
            $replyKind,
            null,
        );
    }

    public function publishEnd(
        TelegramClient $telegram,
        FightEndResult $result,
        ?int $chatId,
        ?int $statusMessageId,
        ?Fight $fightBeforeClear,
    ): void {
        if ($chatId === null) {
            return;
        }

        if ($fightBeforeClear instanceof Fight && $fightBeforeClear->tg_log_message_id !== null) {
            $telegram->deleteMessage($chatId, $fightBeforeClear->tg_log_message_id);
        }

        if ($statusMessageId !== null) {
            $this->publishEndStatus(
                $telegram,
                $chatId,
                $statusMessageId,
                $result->editText,
                TelegramKeyboards::clearInline(),
                $fightBeforeClear,
            );
        }

        if ($result->replyText === null || $result->replyUi === FightEndUiEnum::None) {
            return;
        }

        $telegram->sendMessage(
            $chatId,
            $result->replyText,
            TelegramKeyboards::fightEndMarkup($result->replyUi, $result->character),
        );
    }

    public function refresh(TelegramClient $telegram, Fight $fight, Character $player): void
    {
        $chatId = $fight->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        if ($fight->tg_log_message_id !== null) {
            $telegram->deleteMessage($chatId, $fight->tg_log_message_id);
        }

        $caption = $this->fightStatus->panelCaption($fight, $player);
        $replyKind = TelegramKeyboards::fightMarkupKind($fight);
        $markup = TelegramKeyboards::fightInlineForStep($fight);
        $statusId = $this->refreshPanel($telegram, $chatId, $fight, $caption, $markup);
        $this->fights->rememberTelegramPanel($fight, (int) $chatId, $statusId, $replyKind, null);
    }

    public function showError(TelegramClient $telegram, Fight $fight, Character $player, string $error): void
    {
        $chatId = $fight->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        $caption = $this->fightStatus->errorCaption($fight, $player, $error);
        $replyKind = TelegramKeyboards::fightMarkupKind($fight);
        $markup = TelegramKeyboards::fightInlineForStep($fight);
        $statusId = $this->refreshPanel($telegram, $chatId, $fight, $caption, $markup);
        $this->fights->rememberTelegramPanel($fight, (int) $chatId, $statusId, $replyKind, null);
    }

    private function enemyImagePath(Fight $fight): ?string
    {
        $enemy = $this->fights->enemy($fight);
        $catalog = EnemyCatalog::query()->where('catalog_id', $enemy->catalogId)->first();

        if (! $catalog instanceof EnemyCatalog) {
            return null;
        }

        return $catalog->localImagePath();
    }

    /**
     * @param  array<string, mixed>  $markup
     */
    private function publishEndStatus(
        TelegramClient $telegram,
        int|string $chatId,
        int $statusMessageId,
        string $caption,
        array $markup,
        ?Fight $fightBeforeClear,
    ): void {
        if ($this->tryEditPanel($telegram, $chatId, $statusMessageId, $caption, $markup)) {
            return;
        }

        $telegram->deleteMessage($chatId, $statusMessageId);

        $imagePath = null;

        if ($fightBeforeClear instanceof Fight) {
            $imagePath = $this->enemyImagePath($fightBeforeClear);
        }

        if ($imagePath !== null) {
            $telegram->sendPhoto($chatId, $imagePath, $caption, $markup);

            return;
        }

        $telegram->sendMessage($chatId, $caption, $markup);
    }

    /**
     * @param  array<string, mixed>  $markup
     */
    private function refreshPanel(
        TelegramClient $telegram,
        int|string $chatId,
        Fight $fight,
        string $caption,
        array $markup,
    ): int {
        $statusId = $fight->tg_message_id;

        if ($statusId !== null && $this->tryEditPanel($telegram, $chatId, $statusId, $caption, $markup)) {
            return $statusId;
        }

        if ($statusId !== null) {
            $telegram->deleteMessage($chatId, $statusId);
        }

        return $this->sendPanelWithRetry($telegram, $chatId, $fight, $caption, $markup);
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function replyPhotoWithRetry(
        TelegramResponder $responder,
        string $imagePath,
        string $caption,
        ?array $markup,
    ): int {
        $attempts = 0;
        $lastError = null;

        while ($attempts < 3) {
            try {
                return $responder->replyPhoto($imagePath, $caption, $markup);
            } catch (RuntimeException $e) {
                $lastError = $e;
                $attempts++;
                Sleep::usleep(200_000 * $attempts);
            }
        }

        throw $lastError;
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function replyTextWithRetry(TelegramResponder $responder, string $text, ?array $markup): int
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts < 3) {
            try {
                return $responder->reply($text, $markup);
            } catch (RuntimeException $e) {
                $lastError = $e;
                $attempts++;
                Sleep::usleep(200_000 * $attempts);
            }
        }

        throw $lastError;
    }

    /**
     * @param  array<string, mixed>  $markup
     */
    private function sendPanelWithRetry(
        TelegramClient $telegram,
        int|string $chatId,
        Fight $fight,
        string $caption,
        array $markup,
    ): int {
        $imagePath = $this->enemyImagePath($fight);
        $attempts = 0;
        $lastError = null;

        while ($attempts < 3) {
            try {
                if ($imagePath !== null) {
                    return $telegram->sendPhoto($chatId, $imagePath, $caption, $markup);
                }

                return $telegram->sendMessage($chatId, $caption, $markup);
            } catch (RuntimeException $e) {
                $lastError = $e;
                $attempts++;
                Sleep::usleep(200_000 * $attempts);
            }
        }

        throw $lastError;
    }

    private function statusEditFailed(RuntimeException $e): bool
    {
        return str_contains($e->getMessage(), "message can't be edited")
            || str_contains($e->getMessage(), 'message to edit not found');
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function tryEditPanel(
        TelegramClient $telegram,
        int|string $chatId,
        int $statusId,
        string $caption,
        ?array $markup,
    ): bool {
        try {
            $telegram->editMessageCaption($chatId, $statusId, $caption, $markup);

            return true;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'there is no caption in the message to edit')) {
                try {
                    $telegram->editMessageText($chatId, $statusId, $caption, $markup);

                    return true;
                } catch (RuntimeException $textError) {
                    if (! $this->statusEditFailed($textError)) {
                        throw $textError;
                    }

                    return false;
                }
            }

            if (! $this->statusEditFailed($e)) {
                throw $e;
            }

            return false;
        }
    }
}
