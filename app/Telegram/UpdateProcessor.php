<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\Registration\RegistrationFlow;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\FightHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\Onboarding\OnboardingHandler;
use App\Telegram\Handlers\Registration\RegistrationHandler;
use App\Telegram\Handlers\ShopHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly CharacterService $characters,
        private readonly RegistrationHandler $registration,
        private readonly RegistrationFlow $registrationFlow,
        private readonly OnboardingHandler $onboarding,
        private readonly MenuHandler $menu,
        private readonly ShopHandler $shop,
        private readonly FightHandler $fight,
        private readonly CityHandler $city,
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

            if ($this->replyIfBlocked($update, $responder)) {
                return;
            }

            if ($update->isCallback()) {
                $this->routeCallback($update, $responder);

                return;
            }

            if ($update->isStartCommand()) {
                $this->routeStart($update, $responder);

                return;
            }

            if ($update->isTextMessage()) {
                $this->routeText($update, $responder);
            }
        } catch (Throwable $e) {
            Log::error('Telegram update failed', [
                'error' => $e->getMessage(),
                'update' => $payload,
            ]);
        }
    }

    private function replyIfBlocked(TelegramUpdate $update, TelegramResponder $responder): bool
    {
        $character = Character::withTrashed()->find($update->userId());

        if (! $character instanceof Character) {
            return false;
        }

        if ($character->trashed()) {
            $responder->reply(__('errors.archived'), null);

            return true;
        }

        if ($this->characters->hasActiveBan($character)) {
            $responder->reply(__('errors.banned'), null);

            return true;
        }

        return false;
    }

    private function routeStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $this->registration->handleStart($update, $responder);

        $player = Character::query()->find($update->userId());

        if (
            $player instanceof Character
            && ! $this->registrationFlow->isActive($player)
            && $player->progress_step !== ProgressStepEnum::DONE
        ) {
            $this->onboarding->handleStart($update, $responder);
        }
    }

    private function routeCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();

        if (
            $data === 'ob:rise'
            || $data === 'ob:back'
            || str_starts_with($data, 'ob:city:')
        ) {
            $this->registration->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'ob:')) {
            $player = Character::query()->find($update->userId());

            if ($player instanceof Character && $this->registrationFlow->isActive($player)) {
                $this->registration->handleCallback($update, $responder);

                return;
            }

            $this->onboarding->handleCallback($update, $responder);

            return;
        }

        if (
            str_starts_with($data, 'city:')
            || str_starts_with($data, 'portal:')
            || $data === 'menu:home'
        ) {
            $this->city->handleCallback($update, $responder);

            return;
        }

        if (
            str_starts_with($data, 'menu:')
            || str_starts_with($data, 'inv:')
            || str_starts_with($data, 'backpack:')
            || str_starts_with($data, 'bag:')
            || str_starts_with($data, 'gear:')
            || str_starts_with($data, 'stat:')
            || str_starts_with($data, 'smith:')
        ) {
            if ($data === 'menu:shop') {
                $this->shop->handleCallback($update, $responder);

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

    private function routeText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $character = Character::query()->find($update->userId());

        if ($character instanceof Character && $character->progress_step === ProgressStepEnum::DONE) {
            $this->menu->handleText($update, $responder);

            return;
        }

        if ($character instanceof Character && $this->registrationFlow->isActive($character)) {
            $this->registration->handleText($update, $responder);

            return;
        }

        $this->onboarding->handleText($update, $responder);
    }
}
