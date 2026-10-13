<?php

declare(strict_types=1);

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Actions\DailyContract\DailyContractExpireStaleAction;
use App\Actions\DailyContract\DailyContractTurnInAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\City;
use App\Services\CityMenuService;
use App\Services\Quest\QuestNpcPlacement;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

describe('delivery NPC placement', function (): void {
    it('parks healer at ruins in target city only after accept', function (): void {
        $player = dailyPlayer(98001, 'DelPlaceHeal');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Healer);
        $target = City::query()->findOrFail($offer->target_city_id);
        $placement = app(QuestNpcPlacement::class);

        expect($placement->isHealerAtTavern($player))->toBeTrue()
            ->and($placement->isHealerAtRuins($player))->toBeFalse();

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);

        expect($placement->isHealerAtTavern($player->fresh()))->toBeTrue()
            ->and($placement->isHealerAtRuins($player->fresh()))->toBeFalse();

        $player = placeInCity($player->fresh(), $target->key);

        expect($placement->isHealerAtRuins($player))->toBeTrue()
            ->and($placement->isHealerAtTavern($player))->toBeFalse()
            ->and(app(CityMenuService::class)->tavernMarkup($player))->not->toBeNull();

        $tavern = app(CityMenuService::class)->tavernMarkup($player);
        $tavernJson = json_encode($tavern, JSON_THROW_ON_ERROR);
        expect($tavernJson)->not->toContain('city:healer');

        $ruinsRows = app(CityMenuService::class)->deliveryNpcRowsForZone(
            $player,
            $target,
            App\Enums\City\PveZoneEnum::Ruins,
        );
        expect($ruinsRows)->toHaveCount(1)
            ->and($ruinsRows[0]['callback_data'])->toBe('city:healer');
    });

    it('keeps NPCs in board city while delivery targets another city', function (): void {
        $player = dailyPlayer(98002, 'DelPlaceBoard');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Buyer);
        $placement = app(QuestNpcPlacement::class);

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = $player->fresh();

        expect($offer->target_city_id)->not->toBe($board->id)
            ->and($placement->isBuyerAtTavern($player))->toBeTrue()
            ->and($placement->isBuyerAtBoard($player))->toBeFalse()
            ->and($placement->hasAcceptedDeliveryHere($player, NpcEnum::Buyer))->toBeFalse();
    });

    it('parks buyer at board and blacksmith at forest when both deliveries target same city', function (): void {
        $player = dailyPlayer(98003, 'DelPlaceMulti');
        $buyerOffer = dailyDeliveryOffer($player, NpcEnum::Buyer);
        $target = City::query()->findOrFail($buyerOffer->target_city_id);
        $buyerOffer->status = DailyContractStatusEnum::Accepted;
        $buyerOffer->save();

        $smithOffer = dailyDeliveryOffer($player->fresh(), NpcEnum::Blacksmith);
        $smithOffer->target_city_id = $target->id;
        $params = $smithOffer->params;
        $params['city_name'] = $target->name;
        $params['city_key'] = $target->key;
        $smithOffer->params = $params;
        $smithOffer->status = DailyContractStatusEnum::Accepted;
        $smithOffer->save();

        $player = placeInCity($player->fresh(), $target->key);
        $placement = app(QuestNpcPlacement::class);
        $menu = app(CityMenuService::class);

        expect($placement->isBuyerAtBoard($player))->toBeTrue()
            ->and($placement->isBuyerAtTavern($player))->toBeFalse()
            ->and($placement->isBlacksmithAtForest($player))->toBeTrue()
            ->and($placement->isBlacksmithAtTavern($player))->toBeFalse();

        $tavernJson = json_encode($menu->tavernMarkup($player), JSON_THROW_ON_ERROR);
        expect($tavernJson)->not->toContain('city:buyer')
            ->and($tavernJson)->not->toContain('city:blacksmith');

        expect($menu->deliveryNpcRowsForBoard($player, $target))->toHaveCount(1)
            ->and($menu->deliveryNpcRowsForZone($player, $target, App\Enums\City\PveZoneEnum::Forest))
            ->toHaveCount(1);
    });

    it('parks overseer on hub and bruiser at port for delivery', function (): void {
        $player = dailyPlayer(98004, 'DelPlaceHubPort');
        $board = dailyCity($player);
        $overseerOffer = dailyDeliveryOffer($player, NpcEnum::Overseer);
        $target = City::query()->findOrFail($overseerOffer->target_city_id);

        app(DailyContractAcceptAction::class)->handle($player, $board, $overseerOffer->id);

        $bruiserOffer = dailyDeliveryOffer($player->fresh(), NpcEnum::Bruiser);
        $bruiserOffer->target_city_id = $target->id;
        $params = $bruiserOffer->params;
        $params['city_name'] = $target->name;
        $params['city_key'] = $target->key;
        $bruiserOffer->params = $params;
        $bruiserOffer->status = DailyContractStatusEnum::Accepted;
        $bruiserOffer->save();

        $player = placeInCity($player->fresh(), $target->key);
        $placement = app(QuestNpcPlacement::class);
        $menu = app(CityMenuService::class);

        expect($placement->isOverseerAtHub($player))->toBeTrue()
            ->and($placement->isOverseerAtTavern($player))->toBeFalse()
            ->and($placement->isBruiserAtPort($player))->toBeTrue()
            ->and($placement->isBruiserAtArena($player))->toBeFalse();

        $homeJson = json_encode($menu->homeMarkup($player), JSON_THROW_ON_ERROR);
        expect($homeJson)->not->toContain('city:overseer');

        cityFlowCallback($player->tg_id, 9, 'city:home');
        assertCityEditMarkupHas('daily:overseer:turnin:' . $overseerOffer->id);
        assertCityEditMarkupHas('city:hub');

        $portRows = $menu->deliveryNpcRowsForZone($player, $target, App\Enums\City\PveZoneEnum::Port);
        expect($portRows)->toHaveCount(1)
            ->and($portRows[0]['callback_data'])->toBe('city:bruiser');
    });

    it('after overseer delivery turn-in offers city hub button instead of overseer shop', function (): void {
        $player = dailyPlayer(98010, 'DelPlaceHubTurn');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Overseer);
        $target = City::query()->findOrFail($offer->target_city_id);

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = placeInCity($player->fresh(), $target->key);

        cityFlowCallback($player->tg_id, 9, 'city:home');
        assertCityEditMarkupHas('city:hub');

        cityFlowCallback($player->tg_id, 10, 'city:hub');
        assertCityEditMarkupHas('city:tavern');
        assertCityEditMarkupHas('city:gates');

        cityFlowCallback($player->tg_id, 11, 'city:home');
        cityFlowCallback($player->tg_id, 12, 'daily:overseer:turnin:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed);
        assertCityEditHas('🪙 +' . $offer->reward_silver);
        assertCityEditMarkupHas('city:hub');
        assertCityEditMarkupHas(__('telegram.btn.to_city'));

        cityFlowCallback($player->tg_id, 13, 'city:hub');
        assertCityEditMarkupHas('city:tavern');
        assertCityEditMarkupHas('city:gates');
    });

    it('returns NPC to tavern after delivery turn-in', function (): void {
        $player = dailyPlayer(98005, 'DelPlaceTurnIn');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Healer);
        $target = City::query()->findOrFail($offer->target_city_id);

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = placeInCity($player->fresh(), $target->key);

        $turnIn = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            NpcEnum::Healer,
        );

        expect($turnIn->ok)->toBeTrue();

        $player = $player->fresh();
        $placement = app(QuestNpcPlacement::class);

        expect($placement->isHealerAtRuins($player))->toBeFalse()
            ->and($placement->isHealerAtTavern($player))->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed);
    });

    it('does not move NPC for offered delivery', function (): void {
        $player = dailyPlayer(98006, 'DelPlaceOffered');
        $offer = dailyDeliveryOffer($player, NpcEnum::Healer);
        $target = City::query()->findOrFail($offer->target_city_id);
        $player = placeInCity($player, $target->key);
        $placement = app(QuestNpcPlacement::class);

        expect($offer->status)->toBe(DailyContractStatusEnum::Offered)
            ->and($placement->isHealerAtRuins($player))->toBeFalse()
            ->and($placement->isHealerAtTavern($player))->toBeTrue();
    });

    it('clears delivery placement after expire', function (): void {
        $player = dailyPlayer(98007, 'DelPlaceExpire');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Buyer);
        $target = City::query()->findOrFail($offer->target_city_id);

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = placeInCity($player->fresh(), $target->key);

        expect(app(QuestNpcPlacement::class)->isBuyerAtBoard($player))->toBeTrue();

        Date::setTestNow(Date::now(config('app.timezone'))->addDay());
        app(DailyContractExpireStaleAction::class)->handle($player->tg_id);

        $player = $player->fresh();
        expect(app(QuestNpcPlacement::class)->isBuyerAtBoard($player))->toBeFalse()
            ->and(app(QuestNpcPlacement::class)->isBuyerAtTavern($player))->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Expired);

        Date::setTestNow();
    });

    it('rejects bruiser city panel when no accepted delivery parks him at port', function (): void {
        $player = dailyPlayer(98008, 'DelPlaceBruiserNo');
        cityFlowCallback($player->tg_id, 9, 'city:bruiser');

        assertCityEditHas(__('telegram.npc.bruiser.error.not_here'));
    });

    it('opens bruiser delivery panel at port and completes turn-in', function (): void {
        $player = dailyPlayer(98009, 'DelPlaceBruiserOk');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Bruiser);
        $target = City::query()->findOrFail($offer->target_city_id);

        cityFlowCallback($player->tg_id, 9, 'daily:accept:' . $offer->id);
        $player = placeInCity($player->fresh(), $target->key);
        $silverBefore = $player->silver;

        cityFlowCallback($player->tg_id, 10, 'city:port');
        assertCityEditMarkupHas('city:bruiser');

        cityFlowCallback($player->tg_id, 11, 'city:bruiser');
        assertCityEditMarkupHas('daily:bruiser:list');
        assertCityEditMarkupHas('city:port');

        cityFlowCallback($player->tg_id, 12, 'daily:bruiser:list');
        assertCityEditMarkupHas('daily:bruiser:turnin:' . $offer->id);

        cityFlowCallback($player->tg_id, 13, 'daily:bruiser:turnin:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse()
            ->and($player->fresh()->silver)->toBe($silverBefore + $offer->reward_silver);
    });
});
