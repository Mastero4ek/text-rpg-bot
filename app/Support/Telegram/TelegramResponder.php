<?php

declare(strict_types=1);

namespace App\Support\Telegram;

final class TelegramResponder
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramUpdate $update,
    ) {}

    public static function errorMessage(?string $error): string
    {
        if ($error === null) {
            return __('common.error');
        }

        return $error;
    }

    public function answerCallback(): void
    {
        if (! $this->update->isCallback()) {
            return;
        }

        $this->client->answerCallbackQuery($this->update->callbackQueryId());
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function reply(string $text, ?array $replyMarkup): int
    {
        return $this->client->sendMessage($this->update->chatId(), $text, $replyMarkup);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function edit(string $text, ?array $replyMarkup): void
    {
        $this->client->editMessageText(
            $this->update->chatId(),
            $this->update->messageId(),
            $text,
            $replyMarkup,
        );
    }
}
