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

        if (
            ! array_key_exists('result', $json)
            || ! is_array($json['result'])
            || ! array_key_exists('message_id', $json['result'])
            || ! is_int($json['result']['message_id'])
        ) {
            throw new RuntimeException('Telegram sendMessage missing message_id.');
        }

        return $json['result']['message_id'];
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
