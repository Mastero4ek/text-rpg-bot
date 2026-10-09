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

    public function chatId(): int|string
    {
        return $this->update->chatId();
    }

    public function deleteUpdateMessage(): void
    {
        $this->deleteMessage($this->update->messageId());
    }

    public function deleteMessage(int $messageId): void
    {
        $this->client->deleteMessage($this->update->chatId(), $messageId);
    }

    /**
     * @param  list<int>  $messageIds
     */
    public function deleteMessages(array $messageIds): void
    {
        foreach ($messageIds as $messageId) {
            $this->deleteMessage($messageId);
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function edit(string $text, ?array $replyMarkup): void
    {
        if ($this->update->callbackHasPhoto()) {
            $this->client->editMessageCaption(
                $this->update->chatId(),
                $this->update->messageId(),
                $text,
                $replyMarkup,
            );

            return;
        }

        $this->editAt(
            $this->update->chatId(),
            $this->update->messageId(),
            $text,
            $replyMarkup,
        );
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editAt(int|string $chatId, int $messageId, string $text, ?array $replyMarkup): void
    {
        $this->client->editMessageText(
            $chatId,
            $messageId,
            $text,
            $replyMarkup,
        );
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editCaptionAt(
        int|string $chatId,
        int $messageId,
        string $caption,
        ?array $replyMarkup,
    ): void {
        $this->client->editMessageCaption(
            $chatId,
            $messageId,
            $caption,
            $replyMarkup,
        );
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editPhoto(string $photoPath, string $caption, ?array $replyMarkup): void
    {
        $this->client->editMessageMedia(
            $this->update->chatId(),
            $this->update->messageId(),
            $photoPath,
            $caption,
            $replyMarkup,
        );
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editPhotoAt(
        int|string $chatId,
        int $messageId,
        string $photoPath,
        string $caption,
        ?array $replyMarkup,
    ): void {
        $this->client->editMessageMedia(
            $chatId,
            $messageId,
            $photoPath,
            $caption,
            $replyMarkup,
        );
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
    public function replyPhoto(string $photoPath, string $caption, ?array $replyMarkup): int
    {
        return $this->client->sendPhoto(
            $this->update->chatId(),
            $photoPath,
            $caption,
            $replyMarkup,
        );
    }
}
