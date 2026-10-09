<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Models\Character;
use App\Services\CharacterService;
use App\Services\Registration\RegistrationFlow;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\BlacksmithHandler;
use App\Telegram\Handlers\BuyerHandler;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\FightHandler;
use App\Telegram\Handlers\HealerHandler;
use App\Telegram\Handlers\InventoryHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\OnboardingHandler;
use App\Telegram\Handlers\RegistrationHandler;
use App\Telegram\Handlers\SmithHandler;
use Illuminate\Support\Facades\Cache;
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
        private readonly InventoryHandler $inventory,
        private readonly SmithHandler $smith,
        private readonly BlacksmithHandler $blacksmith,
        private readonly HealerHandler $healer,
        private readonly BuyerHandler $buyer,
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

            if (! Cache::add('telegram:update:' . $update->updateId(), 1, now()->addDay())) {
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
            && ! $player->progress_step->canPlayCity()
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

        if (str_starts_with($data, 'city:blacksmith')) {
            $this->blacksmith->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'city:healer')) {
            $this->healer->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'city:buyer')) {
            $this->buyer->handleCallback($update, $responder);

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

        if ($data === 'menu:smith' || str_starts_with($data, 'smith:')) {
            $this->smith->handleCallback($update, $responder);

            return;
        }

        if (
            str_starts_with($data, 'inv:')
            || str_starts_with($data, 'backpack:')
            || str_starts_with($data, 'bag:')
            || str_starts_with($data, 'gear:')
            || $data === 'menu:inv'
            || $data === 'menu:bag'
            || $data === 'menu:gear'
            || str_starts_with($data, 'menu:backpack')
        ) {
            $this->inventory->handleCallback($update, $responder);

            return;
        }

        if (
            str_starts_with($data, 'menu:')
            || str_starts_with($data, 'stat:')
        ) {
            $this->menu->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'fight:')) {
            $this->fight->handleCallback($update, $responder);

            return;
        }

        $responder->answerCallback();
    }

    private function routeText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $character = Character::query()->find($update->userId());

        if ($character instanceof Character && $character->progress_step->canPlayCity()) {
            if ($update->botCommand() !== null) {
                $this->menu->handleCommand($update, $responder);

                return;
            }

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
