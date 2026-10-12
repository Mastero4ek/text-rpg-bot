<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\ProgressStepEnum;
use App\Enums\SkinEnum;
use App\Models\Character;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\RegistrationKeyboard;
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
            ProgressStepEnum::SET_SKIN,
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

        if ($character->progress_step === ProgressStepEnum::SET_SKIN) {
            if ($data === 'ob:back') {
                return true;
            }

            if (str_starts_with($data, 'ob:skin:pick:')) {
                return SkinEnum::tryFrom(mb_substr($data, 13)) instanceof SkinEnum;
            }

            if (str_starts_with($data, 'ob:skin:')) {
                return SkinEnum::tryFrom(mb_substr($data, 8)) instanceof SkinEnum;
            }

            return false;
        }

        if ($character->progress_step === ProgressStepEnum::SET_CITY) {
            return str_starts_with($data, 'ob:city:');
        }

        return false;
    }

    public function nudgeError(Character $character): string
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            return LangVariant::pick('telegram.registration.error.splash_need_rise');
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            return LangVariant::pick('telegram.registration.error.nick_need_name');
        }

        if ($character->progress_step === ProgressStepEnum::SET_SKIN) {
            return $this->registration->skinButtonError();
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
                RegistrationKeyboard::splash(),
            );

            return;
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            $this->editOrSend(
                $responder,
                $character,
                $this->nickText($error),
                RegistrationKeyboard::nickBack(),
            );

            return;
        }

        if ($character->progress_step === ProgressStepEnum::SET_SKIN) {
            if ($character->username === null) {
                throw new RuntimeException('Registration skin screen requires username.');
            }

            $preview = $this->registration->skinPreview($character);

            $this->editOrSend(
                $responder,
                $character,
                $this->skinText($character->username, $preview, $error),
                RegistrationKeyboard::skin($preview),
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
            RegistrationKeyboard::city($this->registration->cities()),
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

    public function showSwappingPhoto(TelegramResponder $responder, Character $character): void
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            $this->replacePhotoOrSend(
                $responder,
                $character,
                $this->splashText(null),
                RegistrationKeyboard::splash(),
            );

            return;
        }

        if ($character->progress_step === ProgressStepEnum::SET_NICK) {
            $this->replacePhotoOrSend(
                $responder,
                $character,
                $this->nickText(null),
                RegistrationKeyboard::nickBack(),
            );

            return;
        }

        if ($character->progress_step === ProgressStepEnum::SET_SKIN) {
            if ($character->username === null) {
                throw new RuntimeException('Registration skin screen requires username.');
            }

            $preview = $this->registration->skinPreview($character);

            $this->replacePhotoOrSend(
                $responder,
                $character,
                $this->skinText($character->username, $preview, null),
                RegistrationKeyboard::skin($preview),
            );

            return;
        }

        throw new RuntimeException('Registration photo swap only on splash, nick, or skin.');
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
                $responder->editCaptionAt(
                    $character->tg_chat_id,
                    $character->tg_message_id,
                    $text,
                    $replyMarkup,
                );

                return;
            } catch (Throwable) {
            }
        }

        $this->sendPhotoAnchor($responder, $character, $text, $replyMarkup);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function replacePhotoOrSend(
        TelegramResponder $responder,
        Character $character,
        string $text,
        ?array $replyMarkup,
    ): void {
        if ($character->tg_chat_id !== null && $character->tg_message_id !== null) {
            try {
                $responder->editPhotoAt(
                    $character->tg_chat_id,
                    $character->tg_message_id,
                    $this->imageForCharacter($character),
                    $text,
                    $replyMarkup,
                );

                return;
            } catch (Throwable) {
            }
        }

        $this->sendPhotoAnchor($responder, $character, $text, $replyMarkup);
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

    public function skinText(string $name, SkinEnum $preview, ?string $error): string
    {
        $text = __('telegram.registration.pick_skin', ['name' => TelegramHtml::escape($name)]);
        $quote = __('telegram.registration.skin_quote.' . $preview->value);
        $text .= "\n\n<blockquote>" . $quote . '</blockquote>';

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

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function sendPhotoAnchor(
        TelegramResponder $responder,
        Character $character,
        string $text,
        ?array $replyMarkup,
    ): void {
        $messageId = $responder->replyPhoto(
            $this->imageForCharacter($character),
            $text,
            $replyMarkup,
        );
        $this->registration->rememberTelegramMessage($character, $responder->chatId(), $messageId);
    }

    private function imageForCharacter(Character $character): string
    {
        if ($character->progress_step === ProgressStepEnum::SPLASH) {
            $path = config('bot.registration_rest_image');
        } elseif ($character->progress_step === ProgressStepEnum::SET_NICK) {
            $path = config('bot.registration_up_image');
        } else {
            $skin = $character->skin;

            if (! $skin instanceof SkinEnum) {
                $skin = $this->registration->skinPreview($character);
            }

            $images = config('bot.character_skin_images');

            if (! is_array($images) || ! isset($images[$skin->value]) || ! is_string($images[$skin->value])) {
                throw new RuntimeException('Character skin image path is not set.');
            }

            $path = $images[$skin->value];
        }

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Registration image path is not set.');
        }

        return $path;
    }
}
