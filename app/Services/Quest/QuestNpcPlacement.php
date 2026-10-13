<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Actions\DailyContract\DailyContractExpireStaleAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Enums\Npc\NpcSpotEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;

final class QuestNpcPlacement
{
    public function __construct(
        private readonly DailyContractExpireStaleAction $expireStale,
    ) {}

    public function cityHasBruiser(Character $character): bool
    {
        $character->loadMissing('city');

        return $character->city instanceof City && $character->city->has_bruiser;
    }

    /**
     * @return list<string> issuer values with accepted delivery targeting current city
     */
    public function deliveryIssuerValues(Character $character): array
    {
        if ($character->city_id === null) {
            return [];
        }

        $this->expireStale->handle($character->tg_id);

        $values = [];
        $rows = CharacterDailyContract::query()
            ->where('tg_id', $character->tg_id)
            ->where('target_city_id', $character->city_id)
            ->where('type', DailyContractTypeEnum::Delivery)
            ->where('status', DailyContractStatusEnum::Accepted)
            ->get(['issuer_npc']);

        foreach ($rows as $row) {
            $values[] = $row->issuer_npc->value;
        }

        return $values;
    }

    public function hasAcceptedDeliveryHere(Character $character, NpcEnum $issuer): bool
    {
        return in_array($issuer->value, $this->deliveryIssuerValues($character), true);
    }

    public function isBlacksmithAtForest(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Blacksmith)) {
            return true;
        }

        return ! $character->blacksmith_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Blacksmith;
    }

    public function isBlacksmithAtTavern(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Blacksmith)) {
            return false;
        }

        return $character->blacksmith_at_tavern;
    }

    public function isBruiserAtArena(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Bruiser)) {
            return false;
        }

        if (! $this->cityHasBruiser($character)) {
            return false;
        }

        return $character->bruiser_contract_active
            && $character->quest_phase === QuestPhaseEnum::Bruiser
            && ! $character->healer_debt_open;
    }

    public function isBruiserAtPort(Character $character): bool
    {
        return $this->hasAcceptedDeliveryHere($character, NpcEnum::Bruiser);
    }

    public function isBuyerAtBoard(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Buyer)) {
            return true;
        }

        return ! $character->buyer_at_tavern
            && (
                $character->quest_phase === QuestPhaseEnum::Buyer
                || $character->quest_phase === QuestPhaseEnum::Bruiser
            );
    }

    public function isBuyerAtTavern(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Buyer)) {
            return false;
        }

        return $character->buyer_at_tavern;
    }

    public function isHealerAtRuins(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Healer)) {
            return true;
        }

        return ! $character->healer_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Healer;
    }

    public function isHealerAtTavern(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Healer)) {
            return false;
        }

        return $character->healer_at_tavern;
    }

    public function isOverseerAtGates(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Overseer)) {
            return false;
        }

        return ! $character->overseer_at_tavern
            && $character->quest_phase === QuestPhaseEnum::Overseer;
    }

    public function isOverseerAtHub(Character $character): bool
    {
        return $this->hasAcceptedDeliveryHere($character, NpcEnum::Overseer);
    }

    public function isOverseerAtTavern(Character $character): bool
    {
        if ($this->hasAcceptedDeliveryHere($character, NpcEnum::Overseer)) {
            return false;
        }

        return $character->overseer_at_tavern;
    }

    public function npcBackCallback(Character $character, NpcEnum $issuer): string
    {
        if ($this->hasAcceptedDeliveryHere($character, $issuer)) {
            return $issuer->deliverySpot()->returnCallback();
        }

        return 'city:tavern';
    }

    public function npcLaterCallback(Character $character, NpcEnum $issuer): string
    {
        if ($issuer === NpcEnum::Overseer && $this->isOverseerAtHub($character)) {
            return 'city:hub';
        }

        return $this->npcBackCallback($character, $issuer);
    }

    /**
     * Issuers hidden from the tavern while an accepted delivery parks them elsewhere in this city.
     *
     * @return list<string>
     */
    public function tavernHiddenByDelivery(Character $character): array
    {
        $hidden = [];

        foreach ($this->deliveryIssuerValues($character) as $value) {
            $issuer = NpcEnum::tryFrom($value);

            if (! $issuer instanceof NpcEnum) {
                continue;
            }

            if ($issuer->deliverySpot() === NpcSpotEnum::Tavern) {
                continue;
            }

            $hidden[] = $value;
        }

        return $hidden;
    }
}
