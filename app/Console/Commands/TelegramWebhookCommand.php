<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BotCommandsSync;
use App\Support\Telegram\TelegramClient;
use Illuminate\Console\Command;
use RuntimeException;

final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action : set|delete}';

    protected $description = 'Set or delete Telegram webhook for the configured bot token.';

    public function handle(TelegramClient $client, BotCommandsSync $commands): int
    {
        $action = $this->argument('action');

        if ($action === 'delete') {
            $client->deleteWebhook();
            $this->info('Webhook deleted.');

            return self::SUCCESS;
        }

        if ($action === 'set') {
            $url = config('bot.webhook_url');
            $secret = config('bot.webhook_secret');

            if (! is_string($url) || $url === '') {
                throw new RuntimeException('TELEGRAM_WEBHOOK_URL is empty.');
            }

            if (! is_string($secret) || $secret === '') {
                throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET is empty.');
            }

            $client->setWebhook($url, $secret);
            $commands->sync();
            $this->info('Webhook set to ' . $url);
            $this->info('Bot commands synced.');

            return self::SUCCESS;
        }

        $this->error('Action must be set or delete.');

        return self::FAILURE;
    }
}
