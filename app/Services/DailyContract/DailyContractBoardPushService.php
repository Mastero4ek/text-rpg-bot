<?php

declare(strict_types=1);

namespace App\Services\DailyContract;

use App\Models\Character\Character;
use App\Models\City;
use App\Services\CityMenuService;
use App\Services\Fight\FightService;
use App\Services\Registration\RegistrationService;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class DailyContractBoardPushService
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly FightService $fights,
        private readonly RegistrationService $registration,
        private readonly TelegramClient $telegram,
    ) {}

    public function notify(Character $character): void
    {
        $chatId = $character->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        $city = $this->cityMenu->currentCity($character);

        if (! $city instanceof City) {
            return;
        }

        $cityName = TelegramHtml::escape($city->name);
        $text = str_replace(':city', $cityName, LangVariant::pick('telegram.daily.board_refreshed'));
        $markup = CityKeyboard::boardRefreshed($city->name);
        $imagePath = $this->boardImagePath();
        $inFight = $this->fights->exists($character->tg_id);
        $responder = TelegramResponder::forChat($this->telegram, $chatId);

        if ($inFight) {
            $this->sendDetached($responder, $imagePath, $text, $markup);

            return;
        }

        $messageId = $character->tg_message_id;

        if ($messageId !== null) {
            if ($imagePath !== null) {
                try {
                    $responder->editPhotoAt($chatId, $messageId, $imagePath, $text, $markup);
                    $this->registration->rememberTelegramMessage($character, $chatId, $messageId);

                    return;
                } catch (Throwable) {
                }
            }

            try {
                $responder->editCaptionAt($chatId, $messageId, $text, $markup);
                $this->registration->rememberTelegramMessage($character, $chatId, $messageId);

                return;
            } catch (Throwable) {
            }

            try {
                $responder->editAt($chatId, $messageId, $text, $markup);
                $this->registration->rememberTelegramMessage($character, $chatId, $messageId);

                return;
            } catch (Throwable) {
            }
        }

        $sentId = $this->sendDetached($responder, $imagePath, $text, $markup);
        $this->registration->rememberTelegramMessage($character, $chatId, $sentId);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function sendDetached(
        TelegramResponder $responder,
        ?string $imagePath,
        string $text,
        array $markup,
    ): int {
        if ($imagePath !== null) {
            return $responder->replyPhoto($imagePath, $text, $markup);
        }

        return $responder->reply($text, $markup);
    }

    private function boardImagePath(): ?string
    {
        $image = config('bot.city_quest_board_image');

        if (! is_string($image) || $image === '' || ! is_file($image)) {
            return null;
        }

        return $image;
    }
}
