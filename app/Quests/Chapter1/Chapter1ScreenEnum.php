<?php

declare(strict_types=1);

namespace App\Quests\Chapter1;

use Filament\Support\Contracts\HasLabel;

enum Chapter1ScreenEnum: string implements HasLabel
{
    case RegistrationSplash = 'registration_splash';
    case RegistrationSetNick = 'registration_set_nick';
    case RegistrationSetCity = 'registration_set_city';
    case OverseerGates = 'overseer_gates';
    case OverseerTavern = 'overseer_tavern';
    case OverseerIntro = 'overseer_intro';
    case OverseerAwaitFight = 'overseer_await_fight';
    case OverseerStats = 'overseer_stats';
    case HealerMeet = 'healer_meet';
    case HealerPotionLock = 'healer_potion_lock';
    case BlacksmithMeet = 'blacksmith_meet';
    case BuyerMeet = 'buyer_meet';
    case BuyerTurnIn = 'buyer_turn_in';
    case BruiserArrive = 'bruiser_arrive';
    case HealerDebt = 'healer_debt';
    case HealerRatLoot = 'healer_rat_loot';
    case BruiserKnucklePending = 'bruiser_knuckle_pending';
    case BruiserReadyForPurse = 'bruiser_ready_for_purse';
    case Chapter1Done = 'chapter1_done';

    /**
     * @return array<string, string>
     */
    public static function optionsForSection(int $section): array
    {
        $options = [];

        foreach (self::cases() as $screen) {
            if ($screen->section() !== $section) {
                continue;
            }

            $options[$screen->value] = $screen->getLabel();
        }

        return $options;
    }

    public function getLabel(): string
    {
        return __('admin.quest_screens.chapter1.' . $this->value);
    }

    public function requiresCity(): bool
    {
        return match ($this) {
            self::RegistrationSplash,
            self::RegistrationSetNick,
            self::RegistrationSetCity => false,
            default => true,
        };
    }

    public function section(): int
    {
        return match ($this) {
            self::RegistrationSplash,
            self::RegistrationSetNick,
            self::RegistrationSetCity => 1,
            self::OverseerGates,
            self::OverseerTavern,
            self::OverseerIntro,
            self::OverseerAwaitFight,
            self::OverseerStats => 2,
            self::HealerMeet,
            self::HealerPotionLock => 3,
            self::BlacksmithMeet => 4,
            self::BuyerMeet => 5,
            self::BruiserArrive,
            self::HealerDebt,
            self::HealerRatLoot,
            self::BruiserKnucklePending,
            self::BruiserReadyForPurse,
            self::BuyerTurnIn => 6,
            self::Chapter1Done => 7,
        };
    }
}
