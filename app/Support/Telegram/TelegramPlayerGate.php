<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Services\CharacterService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Registration\RegistrationFlow;

final class TelegramPlayerGate
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly LoadoutService $loadout,
        private readonly RegistrationFlow $registration,
        private readonly OnboardingService $onboarding,
    ) {}

    /**
     * City-ready player after regen/loadout drop, or null when a reply/nudge was already sent.
     */
    public function requireCityPlayer(TelegramUpdate $update, TelegramResponder $responder): Character|false
    {
        if (! $update->hasFrom()) {
            return false;
        }

        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return false;
        }

        $player = $this->characters->applyRegen($player);
        $player = $this->loadout->dropUnmetEquipped($player);

        if ($this->registration->isActive($player)) {
            $this->registration->showNudge($responder, $player);

            return false;
        }

        if (! $player->progress_step->canPlayCity()) {
            $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);

            return false;
        }

        return $player;
    }
}
