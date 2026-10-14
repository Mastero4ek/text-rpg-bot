<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Registration\SetLocationAction;
use App\Actions\Registration\SetNickAction;
use App\Actions\Registration\SetSkinAction;
use App\Enums\ProgressStepEnum;
use App\Enums\SkinEnum;
use App\Models\Character\Character;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\Character\CharacterService;
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
        private readonly SetSkinAction $setSkin,
        private readonly SetLocationAction $setLocation,
        private readonly CityHandler $city,
        private readonly QuestHandler $quest,
        private readonly OverseerQuest $overseerQuest,
        private readonly HealerQuest $healerQuest,
    ) {}

    public function handleStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->registration->ensurePlayer($update->userId());

        if (
            $player->progress_step === ProgressStepEnum::DONE
            || $player->progress_step === ProgressStepEnum::ARRIVED
        ) {
            $player = $this->characters->applyRegen($player);
            // Send first, then drop the old anchor + /start. Deleting /start before send
            // empties the chat → Telegram shows «Начать» again → appear/delete loop.
            $oldPanelId = $player->tg_message_id;
            $player = $this->registration->forgetTelegramMessage($player);

            if ($this->overseerQuest->awaitsMeet($player)) {
                $meetMessageId = $this->quest->showMeet($responder, $player);
                $this->registration->rememberTelegramMessage($player, $responder->chatId(), $meetMessageId);
                $this->dropStaleMessages($responder, $oldPanelId);

                return;
            }

            if ($this->overseerQuest->isTrainLocked($player)) {
                $this->quest->resumeLocked($responder, $player);
                $this->dropStaleMessages($responder, $oldPanelId);

                return;
            }

            if ($this->healerQuest->isPotionLocked($player)) {
                $meetMessageId = $this->quest->showHealerMeet($responder, $player);
                $this->registration->rememberTelegramMessage($player, $responder->chatId(), $meetMessageId);
                $this->dropStaleMessages($responder, $oldPanelId);

                return;
            }

            $homeMessageId = $this->city->sendHomePanel($responder, $player);
            $this->registration->rememberTelegramMessage($player, $responder->chatId(), $homeMessageId);
            $this->dropStaleMessages($responder, $oldPanelId);

            return;
        }

        if ($this->flow->isActive($player)) {
            $oldPanelId = $player->tg_message_id;
            $player = $this->registration->forgetTelegramMessage($player);
            $this->flow->showClean($responder, $player);
            $this->dropStaleMessages($responder, $oldPanelId);
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
            $this->flow->showSwappingPhoto($responder, $res->character);

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
            $this->back($update, $responder);

            return;
        }

        if (str_starts_with($data, 'ob:skin:pick:')) {
            $this->chooseSkin($update, $responder, mb_substr($data, 13));

            return;
        }

        if (str_starts_with($data, 'ob:skin:')) {
            $this->previewSkin($update, $responder, mb_substr($data, 8));

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

    private function back(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            return;
        }

        if ($player->progress_step === ProgressStepEnum::SET_NICK) {
            $player = $this->registration->backToSplash($player);
            $this->flow->syncAnchor($player, $update);
            $this->flow->showSwappingPhoto($responder, $player);

            return;
        }

        if ($player->progress_step === ProgressStepEnum::SET_SKIN) {
            $player = $this->registration->backToNick($player);
            $this->flow->syncAnchor($player, $update);
            $this->flow->showSwappingPhoto($responder, $player);
        }
    }

    private function previewSkin(TelegramUpdate $update, TelegramResponder $responder, string $skinKey): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_SKIN) {
            return;
        }

        $skin = SkinEnum::tryFrom($skinKey);

        if (! $skin instanceof SkinEnum) {
            return;
        }

        if ($this->registration->skinPreview($player) === $skin) {
            return;
        }

        $this->registration->rememberSkinPreview($player, $skin);
        $this->flow->syncAnchor($player, $update);
        $this->flow->showSwappingPhoto($responder, $player);
    }

    private function chooseSkin(TelegramUpdate $update, TelegramResponder $responder, string $skinKey): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_SKIN) {
            return;
        }

        $res = $this->setSkin->handle($player, $skinKey);

        if (! $res->ok || ! $res->character instanceof Character) {
            $player->refresh();

            if ($player->progress_step !== ProgressStepEnum::SET_SKIN) {
                return;
            }

            $this->flow->show(
                $responder,
                $player,
                TelegramResponder::errorMessage($res->error),
            );

            return;
        }

        $this->flow->syncAnchor($res->character, $update);
        $this->flow->showClean($responder, $res->character);
    }

    private function chooseCity(TelegramUpdate $update, TelegramResponder $responder, string $cityKey): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::SET_CITY) {
            return;
        }

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
        $meetMessageId = $this->quest->showMeet($responder, $chosen);
        $this->registration->rememberTelegramMessage($chosen, $responder->chatId(), $meetMessageId);
    }

    private function dropStaleMessages(TelegramResponder $responder, ?int $oldPanelId): void
    {
        if ($oldPanelId !== null) {
            $responder->deleteMessage($oldPanelId);
        }

        $responder->deleteUpdateMessage();
    }
}
