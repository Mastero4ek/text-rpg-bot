<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Bag\BagCatalog;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Services\CombatService;
use App\Support\Demo\DemoMode;
use RuntimeException;

final class DailyContractCopy
{
    public function __construct(
        private readonly CombatService $combat,
    ) {}

    public function acceptDoneText(?CharacterDailyContract $contract): string
    {
        if ($contract instanceof CharacterDailyContract && $contract->type === DailyContractTypeEnum::Delivery) {
            return __('telegram.daily.accept_done_delivery', [
                'npc' => $this->issuerLabel($contract),
                'city' => $this->deliveryCityName($contract),
                'place' => $this->deliveryPlaceLabel($contract),
            ]);
        }

        return __('telegram.daily.accept_done', [
            'zone' => $this->zoneLabel($contract),
            'npc' => $this->issuerLabel($contract),
        ]);
    }

    public function cardText(CharacterDailyContract $contract): string
    {
        $owner = Character::query()->find($contract->tg_id);
        $silver = $contract->reward_silver;
        $exp = $contract->reward_exp;

        if ($owner instanceof Character) {
            $reward = DemoMode::rewardAmounts($owner, $exp, $silver);
            $silver = $reward['silver'];
            $exp = $reward['exp'];
        }

        if ($contract->type === DailyContractTypeEnum::Delivery) {
            $text = __('telegram.daily.card_delivery', [
                'item' => $this->itemName($contract),
                'city' => $this->deliveryCityName($contract),
                'npc' => $this->issuerLabel($contract),
                'place' => $this->deliveryPlaceLabel($contract),
                'silver' => $silver,
                'exp' => $exp,
            ]);

            if ($owner instanceof Character) {
                return DemoMode::withRewardFrozenNotice($owner, $text);
            }

            return $text;
        }

        $lvl = $contract->reward_exp;

        if (array_key_exists('lvl', $contract->params) && is_int($contract->params['lvl'])) {
            $lvl = $contract->params['lvl'];
        }

        $text = __('telegram.daily.card', [
            'zone' => $this->zoneLabel($contract),
            'target' => $this->combat->logName($this->monsterName($contract), $lvl),
            'npc' => $this->issuerLabel($contract),
            'silver' => $silver,
            'exp' => $exp,
        ]);

        if ($owner instanceof Character) {
            return DemoMode::withRewardFrozenNotice($owner, $text);
        }

        return $text;
    }

    public function issuerLabel(?CharacterDailyContract $contract): string
    {
        $npc = NpcEnum::Buyer;

        if ($contract instanceof CharacterDailyContract) {
            $npc = $contract->issuer_npc;
        }

        return __('telegram.daily.npc.' . $npc->value);
    }

    public function offerCardImagePath(CharacterDailyContract $contract): ?string
    {
        if ($contract->type !== DailyContractTypeEnum::Delivery) {
            return null;
        }

        $catalogId = $contract->bag_catalog_id;

        if (! is_string($catalogId) || $catalogId === '') {
            return null;
        }

        $catalog = BagCatalog::query()->find($catalogId);

        if (! $catalog instanceof BagCatalog) {
            return null;
        }

        return $catalog->localImagePath();
    }

    public function rowText(CharacterDailyContract $contract): string
    {
        if ($contract->type === DailyContractTypeEnum::Delivery) {
            return __('telegram.daily.row_delivery', [
                'item' => $this->itemName($contract),
                'city' => $this->deliveryCityName($contract),
            ]);
        }

        return __('telegram.daily.row', [
            'monster' => $this->monsterName($contract),
            'zone' => $this->huntZoneName($contract),
        ]);
    }

    public function turnInTarget(CharacterDailyContract $contract): string
    {
        if ($contract->type === DailyContractTypeEnum::Delivery) {
            return $this->itemName($contract);
        }

        $lvl = $contract->reward_exp;

        if (array_key_exists('lvl', $contract->params) && is_int($contract->params['lvl'])) {
            $lvl = $contract->params['lvl'];
        }

        return $this->combat->logName($this->monsterName($contract), $lvl);
    }

    private function deliveryCityName(CharacterDailyContract $contract): string
    {
        if (array_key_exists('city_name', $contract->params) && is_string($contract->params['city_name'])) {
            return $contract->params['city_name'];
        }

        $contract->loadMissing('targetCity');

        if ($contract->targetCity instanceof City) {
            return $contract->targetCity->name;
        }

        throw new RuntimeException('Delivery contract missing target city name.');
    }

    private function deliveryPlaceLabel(CharacterDailyContract $contract): string
    {
        return __('telegram.daily.place.' . $contract->issuer_npc->deliverySpot()->value);
    }

    private function huntZoneName(CharacterDailyContract $contract): string
    {
        $zoneKey = 'forest';

        if (
            array_key_exists('zone', $contract->params)
            && is_string($contract->params['zone'])
            && $contract->params['zone'] !== ''
        ) {
            $zoneKey = $contract->params['zone'];
        }

        return __('telegram.daily.zone_name.' . $zoneKey);
    }

    private function itemName(CharacterDailyContract $contract): string
    {
        if (array_key_exists('item_name', $contract->params) && is_string($contract->params['item_name'])) {
            return $contract->params['item_name'];
        }

        if (is_string($contract->bag_catalog_id) && $contract->bag_catalog_id !== '') {
            return $contract->bag_catalog_id;
        }

        throw new RuntimeException('Delivery contract missing item name.');
    }

    private function monsterName(CharacterDailyContract $contract): string
    {
        if (array_key_exists('monster_name', $contract->params) && is_string($contract->params['monster_name'])) {
            return $contract->params['monster_name'];
        }

        if (is_string($contract->enemy_catalog_id) && $contract->enemy_catalog_id !== '') {
            return $contract->enemy_catalog_id;
        }

        return 'enemy';
    }

    private function zoneLabel(?CharacterDailyContract $contract): string
    {
        $zoneKey = 'forest';

        if (
            $contract instanceof CharacterDailyContract
            && array_key_exists('zone', $contract->params)
            && is_string($contract->params['zone'])
        ) {
            $zoneKey = $contract->params['zone'];
        }

        return __('telegram.daily.zone.' . $zoneKey);
    }
}
