<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BotCommandsSync;
use Illuminate\Console\Command;

final class TelegramCommandsCommand extends Command
{
    protected $signature = 'telegram:commands';

    protected $description = 'Sync Telegram bot command menu (Character / Skills / Backpack / Bag).';

    public function handle(BotCommandsSync $commands): int
    {
        $commands->sync();
        $this->info('Bot commands synced.');

        return self::SUCCESS;
    }
}
