<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use RuntimeException;

final readonly class TelegramUpdate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function updateId(): int
    {
        if (! array_key_exists('update_id', $this->payload) || ! is_int($this->payload['update_id'])) {
            throw new RuntimeException('Update update_id missing.');
        }

        return $this->payload['update_id'];
    }

    public function userId(): int
    {
        $from = $this->from();

        if (! array_key_exists('id', $from) || ! is_int($from['id'])) {
            throw new RuntimeException('Update from.id missing.');
        }

        return $from['id'];
    }

    public function chatId(): int|string
    {
        if ($this->isCallback()) {
            $message = $this->callbackMessage();

            if (! array_key_exists('chat', $message) || ! is_array($message['chat'])) {
                throw new RuntimeException('Callback chat missing.');
            }

            if (! array_key_exists('id', $message['chat'])) {
                throw new RuntimeException('Callback chat.id missing.');
            }

            $id = $message['chat']['id'];

            if (is_int($id) || is_string($id)) {
                return $id;
            }

            throw new RuntimeException('Callback chat.id invalid.');
        }

        $message = $this->message();

        if (! array_key_exists('chat', $message) || ! is_array($message['chat'])) {
            throw new RuntimeException('Message chat missing.');
        }

        if (! array_key_exists('id', $message['chat'])) {
            throw new RuntimeException('Message chat.id missing.');
        }

        $id = $message['chat']['id'];

        if (is_int($id) || is_string($id)) {
            return $id;
        }

        throw new RuntimeException('Message chat.id invalid.');
    }

    public function isStartCommand(): bool
    {
        if (! $this->isTextMessage()) {
            return false;
        }

        $text = $this->text();

        return $text === '/start' || str_starts_with($text, '/start ');
    }

    public function isTextMessage(): bool
    {
        if (! array_key_exists('message', $this->payload) || ! is_array($this->payload['message'])) {
            return false;
        }

        return array_key_exists('text', $this->payload['message'])
            && is_string($this->payload['message']['text']);
    }

    public function isCallback(): bool
    {
        return array_key_exists('callback_query', $this->payload)
            && is_array($this->payload['callback_query']);
    }

    public function text(): string
    {
        $message = $this->message();

        if (! array_key_exists('text', $message) || ! is_string($message['text'])) {
            throw new RuntimeException('Message text missing.');
        }

        return $message['text'];
    }

    public function callbackData(): string
    {
        $callback = $this->callbackQuery();

        if (! array_key_exists('data', $callback) || ! is_string($callback['data'])) {
            throw new RuntimeException('Callback data missing.');
        }

        return $callback['data'];
    }

    public function callbackQueryId(): string
    {
        $callback = $this->callbackQuery();

        if (! array_key_exists('id', $callback) || ! is_string($callback['id'])) {
            throw new RuntimeException('Callback id missing.');
        }

        return $callback['id'];
    }

    public function messageId(): int
    {
        if ($this->isCallback()) {
            $message = $this->callbackMessage();

            if (! array_key_exists('message_id', $message) || ! is_int($message['message_id'])) {
                throw new RuntimeException('Callback message_id missing.');
            }

            return $message['message_id'];
        }

        $message = $this->message();

        if (! array_key_exists('message_id', $message) || ! is_int($message['message_id'])) {
            throw new RuntimeException('Message message_id missing.');
        }

        return $message['message_id'];
    }

    public function hasFrom(): bool
    {
        try {
            $this->from();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function from(): array
    {
        if ($this->isCallback()) {
            $callback = $this->callbackQuery();

            if (! array_key_exists('from', $callback) || ! is_array($callback['from'])) {
                throw new RuntimeException('Callback from missing.');
            }

            return $callback['from'];
        }

        if (! $this->isTextMessage() && ! array_key_exists('message', $this->payload)) {
            throw new RuntimeException('Update from missing.');
        }

        $message = $this->message();

        if (! array_key_exists('from', $message) || ! is_array($message['from'])) {
            throw new RuntimeException('Message from missing.');
        }

        return $message['from'];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(): array
    {
        if (! array_key_exists('message', $this->payload) || ! is_array($this->payload['message'])) {
            throw new RuntimeException('Update message missing.');
        }

        return $this->payload['message'];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackQuery(): array
    {
        if (! array_key_exists('callback_query', $this->payload) || ! is_array($this->payload['callback_query'])) {
            throw new RuntimeException('Update callback_query missing.');
        }

        return $this->payload['callback_query'];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackMessage(): array
    {
        $callback = $this->callbackQuery();

        if (! array_key_exists('message', $callback) || ! is_array($callback['message'])) {
            throw new RuntimeException('Callback message missing.');
        }

        return $callback['message'];
    }
}
