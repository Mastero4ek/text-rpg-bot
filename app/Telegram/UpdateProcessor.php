<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Actions\Character\CharacterUnbanAction;
use App\Models\Character\Character;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\Character\CharacterJailPushService;
use App\Services\Character\CharacterService;
use App\Services\Registration\RegistrationFlow;
use App\Services\Telegram\IdleSessionService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\BarkeeperHandler;
use App\Telegram\Handlers\BlacksmithHandler;
use App\Telegram\Handlers\BruiserHandler;
use App\Telegram\Handlers\BuyerHandler;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Handlers\DailyContractHandler;
use App\Telegram\Handlers\FightHandler;
use App\Telegram\Handlers\HealerHandler;
use App\Telegram\Handlers\InventoryHandler;
use App\Telegram\Handlers\MenuHandler;
use App\Telegram\Handlers\OverseerHandler;
use App\Telegram\Handlers\QuestHandler;
use App\Telegram\Handlers\RegistrationHandler;
use App\Telegram\Handlers\SmithHandler;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly CharacterService $characters,
        private readonly CharacterJailPushService $jailPush,
        private readonly CharacterUnbanAction $unban,
        private readonly IdleSessionService $idleSession,
        private readonly RegistrationHandler $registration,
        private readonly RegistrationFlow $registrationFlow,
        private readonly QuestHandler $quest,
        private readonly OverseerQuest $overseerQuest,
        private readonly HealerQuest $healerQuest,
        private readonly MenuHandler $menu,
        private readonly InventoryHandler $inventory,
        private readonly SmithHandler $smith,
        private readonly BarkeeperHandler $barkeeper,
        private readonly BlacksmithHandler $blacksmith,
        private readonly BruiserHandler $bruiser,
        private readonly HealerHandler $healer,
        private readonly BuyerHandler $buyer,
        private readonly OverseerHandler $overseer,
        private readonly DailyContractHandler $daily,
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
                if ($this->wakeIdleCallback($update, $responder)) {
                    return;
                }

                $this->routeCallback($update, $responder);
                $this->touchIdleSession($update);

                return;
            }

            $this->trackIdleSession($update, $responder);

            if ($update->isStartCommand()) {
                $this->routeStart($update, $responder);
                $this->trackIdleSession($update, $responder);

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
            $responder->answerCallback();
            $responder->reply(__('errors.archived'), null);

            return true;
        }

        if ($this->characters->hasActiveBan($character)) {
            $responder->answerCallback();
            $this->jailPush->nudge($character, $responder);

            return true;
        }

        if (
            $character->banned_until instanceof CarbonInterface
            && $character->banned_until->isPast()
        ) {
            $responder->answerCallback();
            $this->unban->handle($character);

            return true;
        }

        return false;
    }

    private function routeCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $pageToast = PaginatedListKeyboard::pageToastState($data);

        if ($pageToast !== null) {
            $responder->answerCallbackToast(__('telegram.btn.page_toast', [
                'page' => $pageToast['page'],
                'pages' => $pageToast['pages'],
            ]));

            return;
        }

        if (
            $data === 'ob:rise'
            || $data === 'ob:back'
            || str_starts_with($data, 'ob:city:')
        ) {
            $this->registration->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'quest:')) {
            $this->quest->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'daily:')) {
            $this->daily->handleCallback($update, $responder);

            return;
        }

        $questPlayer = Character::query()->find($update->userId());

        if ($questPlayer instanceof Character && $this->overseerQuest->isTrainLocked($questPlayer)) {
            if (str_starts_with($data, 'fight:')) {
                $this->fight->handleCallback($update, $responder);

                return;
            }

            $responder->answerCallback();
            $this->quest->resumeLocked($responder, $questPlayer);

            return;
        }

        if ($questPlayer instanceof Character && $this->overseerQuest->awaitsMeet($questPlayer)) {
            $responder->answerCallback();
            $this->quest->showMeet($responder, $questPlayer);

            return;
        }

        if ($questPlayer instanceof Character && $this->healerQuest->isPotionLocked($questPlayer)) {
            $responder->answerCallback();
            $this->quest->showHealerMeet($responder, $questPlayer);

            return;
        }

        if (str_starts_with($data, 'ob:')) {
            $player = Character::query()->find($update->userId());

            if ($player instanceof Character && $this->registrationFlow->isActive($player)) {
                $this->registration->handleCallback($update, $responder);

                return;
            }

            $responder->answerCallback();

            return;
        }

        if (str_starts_with($data, 'city:barkeeper')) {
            $this->barkeeper->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'city:blacksmith')) {
            $this->blacksmith->handleCallback($update, $responder);

            return;
        }

        if (str_starts_with($data, 'city:bruiser')) {
            $this->bruiser->handleCallback($update, $responder);

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

        if (str_starts_with($data, 'city:overseer')) {
            $this->overseer->handleCallback($update, $responder);

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

    private function routeStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $this->registration->handleStart($update, $responder);
    }

    private function routeText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $character = Character::query()->find($update->userId());

        if (
            $character instanceof Character
            && (
                $this->overseerQuest->isTrainLocked($character)
                || $this->overseerQuest->awaitsMeet($character)
                || $this->healerQuest->isPotionLocked($character)
            )
        ) {
            return;
        }

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

        $responder->reply(__('common.press_start'), null);
    }

    private function touchIdleSession(TelegramUpdate $update): void
    {
        $player = Character::query()->find($update->userId());

        if (! $player instanceof Character) {
            return;
        }

        $this->idleSession->touch($player);
    }

    private function trackIdleSession(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if (! $player instanceof Character) {
            return;
        }

        $this->idleSession->notifyIfIdle($player, $responder);
        $this->idleSession->touch($player);
    }

    private function wakeIdleCallback(TelegramUpdate $update, TelegramResponder $responder): bool
    {
        $player = Character::query()->find($update->userId());

        if (! $player instanceof Character) {
            return false;
        }

        if (! $this->idleSession->shouldNotify($player)) {
            return false;
        }

        $responder->answerCallback();
        $this->idleSession->notifyIfIdle($player, $responder);

        return true;
    }
}
