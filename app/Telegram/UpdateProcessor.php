<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\FightHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\OnboardingHandler;
use App\Telegram\Handlers\ShopHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly OnboardingHandler $onboarding,
        private readonly MenuHandler $menu,
        private readonly ShopHandler $shop,
        private readonly FightHandler $fight,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        try {
            $update = new TelegramUpdate($payload);

            if (! $update->hasFrom()) {
                return;
            }

            $responder = new TelegramResponder($this->client, $update);

            if ($update->isCallback()) {
                $this->routeCallback($update, $responder);

                return;
            }

            if ($update->isStartCommand()) {
                $this->onboarding->handleStart($update, $responder);

                return;
            }

            if ($update->isTextMessage()) {
                $this->onboarding->handleText($update, $responder);
            }
        } catch (Throwable $e) {
            Log::error('Telegram update failed', [
                'error' => $e->getMessage(),
                'update' => $payload,
            ]);
        }
    }

    private function routeCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();

        if (str_starts_with($data, 'ob:')) {
            $this->onboarding->handleCallback($update, $responder);

            return;
        }

        if (
            str_starts_with($data, 'menu:')
            || str_starts_with($data, 'inv:')
            || str_starts_with($data, 'gear:')
            || str_starts_with($data, 'stat:')
            || str_starts_with($data, 'smith:')
        ) {
            if ($data === 'menu:shop') {
                $this->shop->handleCallback($update, $responder);

                return;
            }

            if ($data === 'menu:fight') {
                $this->fight->handleCallback($update, $responder);

                return;
            }

            $this->menu->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'shop:')) {
            $this->shop->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'fight:')) {
            $this->fight->handleCallback($update, $responder);
        }
    }
}
