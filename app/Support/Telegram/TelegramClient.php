<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramClient
{
    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
        ]);
    }

    public function answerCallbackQueryToast(string $callbackQueryId, string $text): void
    {
        $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => false,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        try {
            $this->post('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        } catch (RuntimeException) {
        }
    }

    public function deleteWebhook(): void
    {
        $this->post('deleteWebhook', []);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageReplyMarkup(
        int|string $chatId,
        int $messageId,
        ?array $replyMarkup,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        try {
            $this->post('editMessageReplyMarkup', $params);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'message is not modified')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageCaption(
        int|string $chatId,
        int $messageId,
        string $caption,
        ?array $replyMarkup,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        try {
            $this->post('editMessageCaption', $params);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'message is not modified')) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageMedia(
        int|string $chatId,
        int $messageId,
        string $photoPath,
        string $caption,
        ?array $replyMarkup,
    ): void {
        if (! is_file($photoPath)) {
            throw new RuntimeException('Telegram registration image missing: ' . $photoPath);
        }

        $photo = file_get_contents($photoPath);

        if ($photo === false) {
            throw new RuntimeException('Telegram registration image unreadable: ' . $photoPath);
        }

        $media = [
            'type' => 'photo',
            'media' => 'attach://photo',
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'media' => json_encode($media, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $response = $this->http()
            ->attach('photo', $photo, basename($photoPath))
            ->post($this->methodUrl('editMessageMedia'), $params);

        if ($response->successful()) {
            $json = $response->json();

            if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] === true) {
                return;
            }
        }

        $body = $response->body();

        if (str_contains($body, 'message is not modified')) {
            return;
        }

        throw new RuntimeException('Telegram editMessageMedia failed: ' . $body);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        try {
            $this->post('editMessageText', $params);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'message is not modified')) {
                return;
            }

            if (str_contains($e->getMessage(), 'there is no text in the message to edit')) {
                $this->editMessageCaption($chatId, $messageId, $text, $replyMarkup);

                return;
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout): array
    {
        $response = $this->http()->get($this->methodUrl('getUpdates'), [
            'offset' => $offset,
            'timeout' => $timeout,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram getUpdates failed: ' . $response->body());
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('result', $json) || ! is_array($json['result'])) {
            throw new RuntimeException('Telegram getUpdates returned invalid payload.');
        }

        $updates = [];

        foreach ($json['result'] as $update) {
            if (is_array($update)) {
                $updates[] = $update;
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup): int
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        $json = $this->post('sendMessage', $params);

        return $this->messageIdFromResult($json, 'sendMessage');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendPhoto(
        int|string $chatId,
        string $photoPath,
        string $caption,
        ?array $replyMarkup,
    ): int {
        if (! is_file($photoPath)) {
            throw new RuntimeException('Telegram photo missing: ' . $photoPath);
        }

        $photo = file_get_contents($photoPath);

        if ($photo === false) {
            throw new RuntimeException('Telegram photo unreadable: ' . $photoPath);
        }

        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        $response = $this->http()
            ->attach('photo', $photo, basename($photoPath))
            ->post($this->methodUrl('sendPhoto'), $params);

        if ($response->successful()) {
            $json = $response->json();

            if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] === true) {
                return $this->messageIdFromResult($json, 'sendPhoto');
            }
        }

        throw new RuntimeException('Telegram sendPhoto failed: ' . $response->body());
    }

    /**
     * @param  list<array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands): void
    {
        $this->post('setMyCommands', [
            'commands' => json_encode($commands, JSON_THROW_ON_ERROR),
        ]);
    }

    public function setWebhook(string $url, string $secretToken): void
    {
        $this->post('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
        ]);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function messageIdFromResult(array $json, string $method): int
    {
        if (
            ! array_key_exists('result', $json)
            || ! is_array($json['result'])
            || ! array_key_exists('message_id', $json['result'])
            || ! is_int($json['result']['message_id'])
        ) {
            throw new RuntimeException("Telegram {$method} missing message_id.");
        }

        return $json['result']['message_id'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function post(string $method, array $params): array
    {
        $response = $this->http()->asForm()->post($this->methodUrl($method), $params);

        if ($response->successful()) {
            $json = $response->json();

            if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] === true) {
                return $json;
            }
        }

        $body = $response->body();

        throw new RuntimeException("Telegram {$method} failed: {$body}");
    }

    private function http(): PendingRequest
    {
        return Http::timeout(60)->acceptJson();
    }

    private function methodUrl(string $method): string
    {
        $token = config('bot.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(__('common.missing_token'));
        }

        $base = config('bot.api_base');

        if (! is_string($base) || $base === '') {
            throw new RuntimeException('TELEGRAM_API_BASE is not set.');
        }

        return mb_rtrim($base, '/') . '/bot' . $token . '/' . $method;
    }
}
