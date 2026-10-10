<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

trait BuildsInlineKeyboard
{
    /**
     * @param  list<list<array{text: string, callback_data: string, style?: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    protected static function keyboardRows(array $rows): array
    {
        return self::inline($rows);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string, style?: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private static function cb(string $text, string $data): array
    {
        return [
            'text' => $text,
            'callback_data' => $data,
        ];
    }

    /**
     * @return array{text: string, callback_data: string, style: string}
     */
    private static function cbDanger(string $text, string $data): array
    {
        return [
            'text' => $text,
            'callback_data' => $data,
            'style' => 'danger',
        ];
    }

    /**
     * @return array{text: string, callback_data: string, style: string}
     */
    private static function cbPrimary(string $text, string $data): array
    {
        return [
            'text' => $text,
            'callback_data' => $data,
            'style' => 'primary',
        ];
    }

    /**
     * @return array{text: string, callback_data: string, style: string}
     */
    private static function cbSuccess(string $text, string $data): array
    {
        return [
            'text' => $text,
            'callback_data' => $data,
            'style' => 'success',
        ];
    }
}
