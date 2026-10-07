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
                'command' => 'character',
                'description' => __('telegram.commands.character'),
            ],
            [
                'command' => 'skills',
                'description' => __('telegram.commands.skills'),
            ],
            [
                'command' => 'backpack',
                'description' => __('telegram.commands.backpack'),
            ],
            [
                'command' => 'bag',
                'description' => __('telegram.commands.bag'),
            ],
        ]);
    }
}
