<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BotCommandsSync;
use App\Support\Telegram\TelegramClient;
use App\Telegram\UpdateProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

final class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll {--once : Process a single getUpdates call then exit}';

    protected $description = 'Long-poll Telegram updates (dev). Refuses when TELEGRAM_WEBHOOK_URL is set.';

    public function handle(TelegramClient $client, UpdateProcessor $processor, BotCommandsSync $commands): int
    {
        $webhookUrl = config('bot.webhook_url');

        if (is_string($webhookUrl) && $webhookUrl !== '') {
            $this->error('TELEGRAM_WEBHOOK_URL is set — use webhook mode, not poll.');

            return self::FAILURE;
        }

        $token = config('bot.token');

        if (! is_string($token) || $token === '') {
            $this->error(__('common.missing_token'));

            return self::FAILURE;
        }

        try {
            $client->deleteWebhook();
        } catch (Throwable $e) {
            $this->warn('deleteWebhook: ' . $e->getMessage());
        }

        try {
            $commands->sync();
        } catch (Throwable $e) {
            $this->warn('setMyCommands: ' . $e->getMessage());
        }

        $this->info('Polling Telegram updates…');
        $offset = 0;
        $timeout = config('bot.poll_timeout');

        if (! is_int($timeout)) {
            $timeout = 25;
        }

        $once = $this->option('once') === true;

        do {
            try {
                $updates = $client->getUpdates($offset, $timeout);

                foreach ($updates as $update) {
                    if (array_key_exists('update_id', $update) && is_int($update['update_id'])) {
                        $offset = $update['update_id'] + 1;
                    }

                    $processor->handle($update);
                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                Sleep::sleep(2);
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
