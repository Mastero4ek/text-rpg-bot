<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;
use Throwable;

final class RegistrationFlow
{
    public function __construct(
        private readonly RegistrationService $registration,
    ) {}

    public function isActive(Character $character): bool
    {
        return $this->isRegistrationStep($character->progress_step);
    }

    public function isRegistrationStep(ProgressStepEnum $step): bool
    {
        return in_array($step, [
            ProgressStepEnum::SPLASH,
            ProgressStepEnum::SET_NICK,
            ProgressStepEnum::SET_CITY,
        ], true);
    }

    public function acceptsCallback(Character $character, string $data): bool
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            return $data === 'ob:rise';
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            return $data === 'ob:back';
        }

        if ($character->progress_step === ProgressStepEnum::SET_CITY) {
            return str_starts_with($data, 'ob:city:');
        }

        return false;
    }

    public function nudgeError(Character $character): string
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            return LangVariant::pick('telegram.registration.errors.splash_need_rise');
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            return LangVariant::pick('telegram.registration.errors.nick_need_name');
        }

        if ($character->progress_step === ProgressStepEnum::SET_CITY) {
            return $this->registration->cityButtonError();
        }

        throw new RuntimeException('Registration nudge outside registration steps.');
    }

    public function show(TelegramResponder $responder, Character $character, ?string $error): void
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            $this->editOrSend(
                $responder,
                $character,
                $this->splashText($error),
                TelegramKeyboards::splash(),
            );

            return;
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            $this->editOrSend(
                $responder,
                $character,
                $this->nickText($error),
                TelegramKeyboards::nickBack(),
            );

            return;
        }

        if ($character->username === null) {
            throw new RuntimeException('Registration city screen requires username.');
        }

        $this->editOrSend(
            $responder,
            $character,
            $this->cityText($character->username, $error),
            TelegramKeyboards::city($this->registration->cities()),
        );
    }

    public function showClean(TelegramResponder $responder, Character $character): void
    {
        $this->show($responder, $character, null);
    }

    public function showNudge(TelegramResponder $responder, Character $character): void
    {
        $this->show($responder, $character, $this->nudgeError($character));
    }

    public function showInputError(TelegramResponder $responder, Character $character): void
    {
        $this->showNudge($responder, $character);
    }

    public function syncAnchor(Character $character, TelegramUpdate $update): Character
    {
        return $this->registration->rememberTelegramMessage(
            $character,
            $update->chatId(),
            $update->messageId(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editOrSend(
        TelegramResponder $responder,
        Character $character,
        string $text,
        ?array $replyMarkup,
    ): void {
        if ($character->tg_chat_id !== null && $character->tg_message_id !== null) {
            try {
                $responder->editAt(
                    $character->tg_chat_id,
                    $character->tg_message_id,
                    $text,
                    $replyMarkup,
                );

                return;
            } catch (Throwable) {
            }
        }

        $messageId = $responder->reply($text, $replyMarkup);
        $this->registration->rememberTelegramMessage($character, $responder->chatId(), $messageId);
    }

    public function cityText(string $name, ?string $error): string
    {
        $text = __('telegram.registration.pick_city', ['name' => TelegramHtml::escape($name)]);

        if ($error === null) {
            return $text;
        }

        return $text . "\n\n" . $error;
    }

    public function nickText(?string $error): string
    {
        $text = __('telegram.registration.ask_nick');

        if ($error === null) {
            return $text;
        }

        return $text . "\n\n" . $error;
    }

    public function splashText(?string $error): string
    {
        $text = __('telegram.registration.splash');

        if ($error === null) {
            return $text;
        }

        return $text . "\n\n" . $error;
    }
}
