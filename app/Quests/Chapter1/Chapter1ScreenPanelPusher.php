<?php

declare(strict_types=1);

namespace App\Quests\Chapter1;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Quests\BlacksmithQuest;
use App\Quests\BruiserQuest;
use App\Quests\BuyerQuest;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\Registration\RegistrationFlow;
use App\Services\Registration\RegistrationService;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Keyboards\RegistrationKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;
use Throwable;

final class Chapter1ScreenPanelPusher
{
    public function __construct(
        private readonly BlacksmithQuest $blacksmithQuest,
        private readonly BruiserQuest $bruiserQuest,
        private readonly BuyerQuest $buyerQuest,
        private readonly CityHandler $city,
        private readonly HealerQuest $healerQuest,
        private readonly OverseerQuest $overseerQuest,
        private readonly RegistrationFlow $registrationFlow,
        private readonly RegistrationService $registration,
        private readonly TelegramClient $telegram,
    ) {}

    public function push(Character $character, Chapter1ScreenEnum $screen): ActionResult
    {
        $chatId = $character->tg_chat_id;
        $messageId = $character->tg_message_id;

        if ($chatId === null || $messageId === null) {
            return ActionResult::fail(__('admin.actions.push_quest_screen.error.no_message'));
        }

        $responder = TelegramResponder::forChat($this->telegram, $chatId);

        if (
            $screen === Chapter1ScreenEnum::Chapter1Done
            || $screen === Chapter1ScreenEnum::BruiserReadyForPurse
        ) {
            try {
                $this->city->resumeHomePanel($responder, $character);
            } catch (Throwable) {
                return ActionResult::fail(__('admin.actions.push_quest_screen.error.telegram'));
            }

            return ActionResult::ok($character);
        }

        try {
            [$imagePath, $caption, $markup] = $this->panel($character, $screen);
            $responder->editPhotoAt($chatId, $messageId, $imagePath, $caption, $markup);
        } catch (Throwable) {
            return ActionResult::fail(__('admin.actions.push_quest_screen.error.telegram'));
        }

        return ActionResult::ok($character);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function panel(Character $character, Chapter1ScreenEnum $screen): array
    {
        return match ($screen) {
            Chapter1ScreenEnum::RegistrationSplash => [
                $this->registrationImage($character),
                $this->registrationFlow->splashText(null),
                RegistrationKeyboard::splash(),
            ],
            Chapter1ScreenEnum::RegistrationSetNick => [
                $this->registrationImage($character),
                $this->registrationFlow->nickText(null),
                RegistrationKeyboard::nickBack(),
            ],
            Chapter1ScreenEnum::RegistrationSetCity => [
                $this->registrationImage($character),
                $this->registrationCityCaption($character),
                RegistrationKeyboard::city($this->registration->cities()),
            ],
            Chapter1ScreenEnum::OverseerGates => [
                $this->overseerQuest->panelImagePath($character),
                $this->overseerQuest->meetSpeech($character),
                TelegramKeyboards::overseerMeet(),
            ],
            Chapter1ScreenEnum::OverseerTavern => [
                $this->overseerQuest->panelImagePath($character),
                $this->overseerQuest->tavernSpeech($character),
                TelegramKeyboards::overseerTrainOffer(),
            ],
            Chapter1ScreenEnum::OverseerIntro,
            Chapter1ScreenEnum::OverseerAwaitFight => [
                $this->overseerQuest->panelImagePath($character),
                $this->overseerQuest->introSpeech(),
                TelegramKeyboards::overseerIntro(),
            ],
            Chapter1ScreenEnum::OverseerStats => [
                $this->overseerQuest->panelImagePath($character),
                $this->overseerQuest->statsSpeech($character),
                TelegramKeyboards::overseerTrainStats(),
            ],
            Chapter1ScreenEnum::HealerMeet,
            Chapter1ScreenEnum::HealerPotionLock => [
                $this->healerQuest->panelImagePath(),
                $this->healerQuest->meetSpeech($character),
                TelegramKeyboards::healerMeet(),
            ],
            Chapter1ScreenEnum::BlacksmithMeet => [
                $this->blacksmithQuest->panelImagePath(),
                $this->blacksmithQuest->meetSpeech($character),
                TelegramKeyboards::blacksmithMeet(),
            ],
            Chapter1ScreenEnum::BuyerMeet => [
                $this->buyerQuest->panelImagePath(),
                $this->buyerQuest->meetSpeech($character),
                TelegramKeyboards::buyerMeet(),
            ],
            Chapter1ScreenEnum::BuyerTurnIn => [
                $this->buyerQuest->panelImagePath(),
                $this->buyerQuest->turnInSpeech($character),
                TelegramKeyboards::buyerTurnIn(),
            ],
            Chapter1ScreenEnum::BruiserArrive => [
                $this->bruiserQuest->panelImagePath(),
                $this->bruiserQuest->meetSpeech($character),
                TelegramKeyboards::bruiserArrive(),
            ],
            Chapter1ScreenEnum::HealerDebt => [
                $this->healerQuest->panelImagePath(),
                $this->healerQuest->debtSpeech($character),
                TelegramKeyboards::healerDebt(),
            ],
            Chapter1ScreenEnum::HealerRatLoot => [
                $this->healerQuest->panelImagePath(),
                $this->healerQuest->ratLootSpeech($character),
                TelegramKeyboards::healerRatLoot(),
            ],
            Chapter1ScreenEnum::BruiserKnucklePending => [
                $this->bruiserQuest->panelImagePath(),
                $this->bruiserQuest->knucklePendingSpeech($character),
                TelegramKeyboards::bruiserKnucklePending(),
            ],
            Chapter1ScreenEnum::BruiserReadyForPurse,
            Chapter1ScreenEnum::Chapter1Done => throw new RuntimeException('Home panels use CityHandler.'),
        };
    }

    private function registrationCityCaption(Character $character): string
    {
        $character->loadMissing('city');
        $name = '';

        if ($character->city instanceof City) {
            $name = TelegramHtml::escape($character->city->name);
        }

        return $this->registrationFlow->cityText($name, null);
    }

    private function registrationImage(Character $character): string
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            $path = config('bot.registration_rest_image');
        } else {
            $path = config('bot.registration_up_image');
        }

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Registration image missing for quest screen push.');
        }

        return $path;
    }
}
