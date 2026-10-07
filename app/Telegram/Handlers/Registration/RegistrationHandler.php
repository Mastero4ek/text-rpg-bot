<?php

declare(strict_types=1);

namespace App\Telegram\Handlers\Registration;

use App\Actions\Registration\SetLocationAction;
use App\Actions\Registration\SetNickAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\CharacterService;
use App\Services\Registration\RegistrationFlow;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CityHandler;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;

final class RegistrationHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly RegistrationService $registration,
        private readonly RegistrationFlow $flow,
        private readonly SetNickAction $setNick,
        private readonly SetLocationAction $setLocation,
        private readonly CityHandler $city,
    ) {}

    public function handleStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->registration->ensurePlayer($update->userId());
        $responder->deleteUpdateMessage();

        if ($player->progress_step === ProgressStepEnum::DONE) {
            $player = $this->characters->applyRegen($player);
            $this->city->sendHome(
                $responder,
                $player,
                __('telegram.registration.welcome_back', [
                    'name' => TelegramHtml::escape((string) $player->username),
                    'profile' => $this->characters->profileText($player),
                ]),
            );

            return;
        }

        if ($this->flow->isActive($player)) {
            $this->flow->showClean($responder, $player);
        }
    }

    public function handleText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return;
        }

        if (! $this->flow->isActive($player)) {
            return;
        }

        if ($player->progress_step === ProgressStepEnum::SET_NICK) {
            $player = $this->registration->rememberPendingDelete($player, $update->messageId());
            $res = $this->setNick->handle($player, $update->text());
            $responder->deleteMessages($this->registration->pendingDeletes($player));

            if (! $res->ok || ! $res->character instanceof Character) {
                $this->flow->show(
                    $responder,
                    $player,
                    TelegramResponder::errorMessage($res->error),
                );

                return;
            }

            $this->registration->clearPendingDeletes($res->character);
            $this->flow->showClean($responder, $res->character);

            return;
        }

        $player = $this->registration->rememberPendingDelete($player, $update->messageId());
        $responder->deleteMessages($this->registration->pendingDeletes($player));
        $this->registration->clearPendingDeletes($player);
        $this->flow->showInputError($responder, $player);
    }

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        $player = Character::query()->find($update->userId());

        if (! $player instanceof Character || ! $this->flow->isActive($player)) {
            return;
        }

        if (! $this->flow->acceptsCallback($player, $data)) {
            $this->flow->syncAnchor($player, $update);
            $this->flow->showNudge($responder, $player);

            return;
        }

        if ($data === 'ob:rise') {
            $this->rise($update, $responder);

            return;
        }

        if ($data === 'ob:back') {
            $this->backToSplash($update, $responder);

            return;
        }

        if (str_starts_with($data, 'ob:city:')) {
            $this->chooseCity($update, $responder, mb_substr($data, 8));
        }
    }

    private function rise(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SPLASH) {
            return;
        }

        $player = $this->registration->rise($player);
        $this->flow->syncAnchor($player, $update);
        $this->flow->showClean($responder, $player);
    }

    private function backToSplash(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_NICK) {
            return;
        }

        $player = $this->registration->backToSplash($player);
        $this->flow->syncAnchor($player, $update);
        $this->flow->showClean($responder, $player);
    }

    private function chooseCity(TelegramUpdate $update, TelegramResponder $responder, string $cityKey): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_CITY) {
            return;
        }

        $res = $this->setLocation->handle($player, $cityKey);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->flow->show(
                $responder,
                $player,
                TelegramResponder::errorMessage($res->error),
            );

            return;
        }

        $chosen = $res->character;
        $chosen->loadMissing('city');

        if ($chosen->city instanceof City) {
            $cityLabel = $chosen->city->name;
        } else {
            $cityLabel = $cityKey;
        }

        if ($chosen->username === null) {
            throw new RuntimeException('Registration final requires username.');
        }

        $this->flow->syncAnchor($chosen, $update);
        $this->flow->editOrSend(
            $responder,
            $chosen,
            __('telegram.registration.done', [
                'city' => TelegramHtml::escape($cityLabel),
                'name' => TelegramHtml::escape($chosen->username),
            ]),
            TelegramKeyboards::clearInline(),
        );

        $this->city->sendHomePanel($responder, $chosen);
    }
}
