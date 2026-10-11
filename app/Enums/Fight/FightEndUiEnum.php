<?php

declare(strict_types=1);

namespace App\Enums\Fight;

enum FightEndUiEnum: string
{
    case None = 'none';
    case MainMenu = 'main_menu';
    case BackToCity = 'back_to_city';
    case OverseerStats = 'overseer_stats';
    case Intro = 'intro';
    case BlacksmithMeet = 'blacksmith_meet';
    case BlacksmithComplete = 'blacksmith_complete';
    case BruiserArrive = 'bruiser_arrive';
    case BruiserTalk = 'bruiser_talk';
    case BruiserComplete = 'bruiser_complete';
    case BruiserKnucklePending = 'bruiser_knuckle_pending';
    case BruiserKnockout = 'bruiser_knockout';
    case HealerDebt = 'healer_debt';
    case HealerRatLoot = 'healer_rat_loot';
    case HealerDebtClosed = 'healer_debt_closed';
}
