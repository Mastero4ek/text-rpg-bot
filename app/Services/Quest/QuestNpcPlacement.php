<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;

final class QuestNpcPlacement
{
    public function isBlacksmithAtForest(Character $character): bool
    {
        return ! $character->blacksmith_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Blacksmith;
    }

    public function isBlacksmithAtTavern(Character $character): bool
    {
        return $character->blacksmith_at_tavern;
    }

    public function isBruiserAtArena(Character $character): bool
    {
        return $character->bruiser_contract_active
            && $character->quest_phase === QuestPhaseEnum::Bruiser
            && ! $character->healer_debt_open;
    }

    public function isBuyerAtBoard(Character $character): bool
    {
        return ! $character->buyer_at_tavern
            && (
                $character->quest_phase === QuestPhaseEnum::Buyer
                || $character->quest_phase === QuestPhaseEnum::Bruiser
            );
    }

    public function isBuyerAtTavern(Character $character): bool
    {
        return $character->buyer_at_tavern;
    }

    public function isHealerAtPortal(Character $character): bool
    {
        return ! $character->healer_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Healer;
    }

    public function isHealerAtTavern(Character $character): bool
    {
        return $character->healer_at_tavern;
    }

    public function isOverseerAtGates(Character $character): bool
    {
        return ! $character->overseer_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Overseer;
    }

    public function isOverseerAtTavern(Character $character): bool
    {
        return $character->overseer_at_tavern;
    }
}
