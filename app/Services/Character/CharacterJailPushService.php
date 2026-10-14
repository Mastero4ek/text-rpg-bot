<?php

declare(strict_types=1);

namespace App\Services\Character;

use App\Models\Character\Character;
use App\Services\Registration\RegistrationService;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Telegram\Keyboards\CityKeyboard;
use Carbon\CarbonInterface;
use Throwable;

final class CharacterJailPushService
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly TelegramClient $telegram,
    ) {}

    public function nudge(Character $character, TelegramResponder $responder): void
    {
        $text = $this->nudgeText($character);
        $imagePath = $this->jailImagePath();

        if ($imagePath !== null) {
            try {
                $responder->replyPhoto($imagePath, $text, null);

                return;
            } catch (Throwable) {
            }
        }

        $responder->reply($text, null);
    }

    public function pushJailed(Character $character): void
    {
        $until = $character->banned_until;

        if (! $until instanceof CarbonInterface) {
            return;
        }

        $caption = __('telegram.jail.panel', [
            'until' => TelegramHtml::escape($this->untilStamp($until)),
        ]);

        $this->sendPanel($character, $caption, null);
    }

    public function pushReleased(Character $character): void
    {
        $this->sendPanel(
            $character,
            __('telegram.jail.released'),
            CityKeyboard::jailReleased(),
        );
    }

    private function jailImagePath(): ?string
    {
        $image = config('bot.jail_image');

        if (! is_string($image) || $image === '' || ! is_file($image)) {
            return null;
        }

        return $image;
    }

    private function nudgeText(Character $character): string
    {
        $until = $character->banned_until;

        if (! $until instanceof CarbonInterface) {
            return __('errors.banned');
        }

        return str_replace(
            ':until',
            TelegramHtml::escape($this->untilStamp($until)),
            LangVariant::pick('telegram.jail.nudge'),
        );
    }

    /**
     * Always send a new message (ban/release must notify). Do not silent-edit an old panel.
     *
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null  $markup
     */
    private function sendPanel(Character $character, string $caption, ?array $markup): void
    {
        $chatId = $character->tg_chat_id;

        if ($chatId === null) {
            return;
        }

        $imagePath = $this->jailImagePath();
        $responder = TelegramResponder::forChat($this->telegram, $chatId);

        if ($imagePath !== null) {
            try {
                $sentId = $responder->replyPhoto($imagePath, $caption, $markup);
                $this->registration->rememberTelegramMessage($character, $chatId, $sentId);

                return;
            } catch (Throwable) {
            }
        }

        try {
            $sentId = $responder->reply($caption, $markup);
            $this->registration->rememberTelegramMessage($character, $chatId, $sentId);
        } catch (Throwable) {
        }
    }

    private function untilStamp(CarbonInterface $until): string
    {
        $local = $until->timezone(config('app.timezone'));
        $tz = config('app.timezone');
        $label = $local->format('T');

        if (is_string($tz) && $tz === 'Europe/Moscow') {
            $label = 'МСК';
        }

        return $local->format('d.m.Y H:i') . ' (' . $label . ')';
    }
}
