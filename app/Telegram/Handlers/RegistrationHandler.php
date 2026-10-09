<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Registration\SetLocationAction;
use App\Actions\Registration\SetNickAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\Registration\RegistrationFlow;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;

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

        if (
            $player->progress_step === ProgressStepEnum::DONE
            || $player->progress_step === ProgressStepEnum::ARRIVED
        ) {
            $player = $this->characters->applyRegen($player);
            $homeMessageId = $this->city->resumeHomePanel($responder, $player);
            $this->registration->rememberTelegramMessage($player, $responder->chatId(), $homeMessageId);

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
        $this->flow->showSwappingPhoto($responder, $player);
    }

    private function backToSplash(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_NICK) {
            return;
        }

        $player = $this->registration->backToSplash($player);
        $this->flow->syncAnchor($player, $update);
        $this->flow->showSwappingPhoto($responder, $player);
    }

    private function chooseCity(TelegramUpdate $update, TelegramResponder $responder, string $cityKey): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_CITY) {
            return;
        }

        $photoMessageId = $update->messageId();
        $res = $this->setLocation->handle($player, $cityKey);

        if (! $res->ok || ! $res->character instanceof Character) {
            $player->refresh();

            if ($player->progress_step->canPlayCity()) {
                return;
            }

            $this->flow->show(
                $responder,
                $player,
                TelegramResponder::errorMessage($res->error),
            );

            return;
        }

        $chosen = $res->character;

        $responder->deleteMessage($photoMessageId);

        $homeMessageId = $this->city->sendHomePanel($responder, $chosen);
        $this->registration->rememberTelegramMessage($chosen, $responder->chatId(), $homeMessageId);
    }
}
