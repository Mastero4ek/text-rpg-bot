<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Telegram\TelegramClient;

final class BotCommandsSync
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {}

    public function sync(): void
    {
        $this->client->setMyCommands([
            [
                'command' => 'city',
                'description' => __('telegram.commands.city'),
            ],
            [
                'command' => 'profile',
                'description' => __('telegram.commands.profile'),
            ],
            [
                'command' => 'backpack',
                'description' => __('telegram.commands.backpack'),
            ],
            [
                'command' => 'bag',
                'description' => __('telegram.commands.bag'),
            ],
            [
                'command' => 'journal',
                'description' => __('telegram.commands.journal'),
            ],
            [
                'command' => 'library',
                'description' => __('telegram.commands.library'),
            ],
            [
                'command' => 'appeal',
                'description' => __('telegram.commands.appeal'),
            ],
        ]);
    }
}
