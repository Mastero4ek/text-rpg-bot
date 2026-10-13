<?php

declare(strict_types=1);

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Actions\DailyContract\DailyContractExpireStaleAction;
use App\Actions\DailyContract\DailyContractMarkReadyAction;
use App\Actions\DailyContract\DailyContractStartHuntAction;
use App\Actions\DailyContract\DailyContractTurnInAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Models\Fight;
use App\Services\Fight\FightEndService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

describe('ensureOffers', function (): void {
    it('creates board_slots offered contracts with level-scaled rewards', function (): void {
        $player = cityDone(97001, 'DailyGen');
        $player->level = 4;
        $player->save();
        $player = $player->fresh();
        $city = dailyCity($player);
        $slotCount = gameConfig()->dailyContracts()['board_slots'];

        $offers = dailyService()->ensureOffers($player, $city);

        expect($offers)->toHaveCount($slotCount);

        $cfg = gameConfig()->dailyContracts();
        $huntSilver = $cfg['economy_balance']['hunt']['silver_multiplier'];
        $huntExp = $cfg['economy_balance']['hunt']['exp_multiplier'];
        $deliverySilver = $cfg['economy_balance']['delivery']['silver_multiplier'];
        $deliveryExp = $cfg['economy_balance']['delivery']['exp_multiplier'];

        foreach ($offers as $row) {
            expect($row->status)->toBe(DailyContractStatusEnum::Offered)
                ->and($row->tg_id)->toBe($player->tg_id)
                ->and($row->city_id)->toBe($city->id)
                ->and(in_array($row->type->value, ['hunt', 'delivery'], true))->toBeTrue();

            if ($row->type->value === 'hunt') {
                expect($row->issuer_npc)->toBe(NpcEnum::Buyer)
                    ->and($row->target_kills)->toBe(1)
                    ->and($row->enemy_catalog_id)->not->toBeNull()
                    ->and($row->reward_silver)->toBe(4 * $huntSilver)
                    ->and($row->reward_exp)->toBe(4 * $huntExp)
                    ->and($row->reward_exp)->toBeGreaterThan($row->reward_silver);
            } else {
                expect($row->bag_catalog_id)->toBe('daily_parcel')
                    ->and($row->target_kills)->toBe(0)
                    ->and($row->enemy_catalog_id)->toBeNull()
                    ->and($row->target_city_id)->not->toBeNull()
                    ->and($row->target_city_id)->not->toBe($city->id)
                    ->and($row->reward_silver)->toBe(4 * $deliverySilver)
                    ->and($row->reward_exp)->toBe(4 * $deliveryExp)
                    ->and($row->reward_silver)->toBeGreaterThan($row->reward_exp);
            }
        }

        expect(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->count())->toBe($slotCount);

        $keys = [];

        foreach ($offers as $row) {
            if ($row->type->value === 'hunt') {
                $key = 'h:' . $row->enemy_catalog_id . ':' . ($row->params['zone'] ?? '');
            } else {
                $key = 'd:' . $row->bag_catalog_id . ':' . $row->target_city_id;
            }

            expect($keys)->not->toHaveKey($key);
            $keys[$key] = true;
        }
    });

    it('does not regenerate offers on the same board date', function (): void {
        $player = cityDone(97002, 'DailyIdem');
        $city = dailyCity($player);
        $slotCount = gameConfig()->dailyContracts()['board_slots'];

        $first = dailyService()->ensureOffers($player, $city);
        $second = dailyService()->ensureOffers($player, $city);

        expect($second->pluck('id')->all())->toBe($first->pluck('id')->all());
        expect(CharacterDailyContract::query()->where('tg_id', $player->tg_id)->count())->toBe($slotCount);
    });

    it('hides accepted slots from the board and filters asks to delivery', function (): void {
        $player = cityDone(97003, 'DailyBoard');
        $city = dailyCity($player);
        $offer = dailyOffer($player);

        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $offered = dailyService()->offeredForBoard($player->fresh(), $city, 'all');
        $asks = dailyService()->offeredForBoard($player->fresh(), $city, 'asks');
        $orders = dailyService()->offeredForBoard($player->fresh(), $city, 'orders');

        expect($offered)->toHaveCount(gameConfig()->dailyContracts()['board_slots'] - 1)
            ->and($offered->contains(fn (CharacterDailyContract $row): bool => $row->id === $offer->id))->toBeFalse();

        foreach ($asks as $row) {
            expect($row->type->value)->toBe('delivery');
        }

        foreach ($orders as $row) {
            expect($row->type->value)->toBe('hunt');
        }
    });
});

describe('accept', function (): void {
    it('moves an offered contract to accepted', function (): void {
        $player = cityDone(97010, 'DailyAcc');
        $city = dailyCity($player);
        $offer = dailyOffer($player);

        $result = app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        expect($result->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
    });

    it('rejects accept for another city', function (): void {
        $player = cityDone(97011, 'DailyAccCity');
        $home = dailyCity($player);
        $offer = dailyOffer($player);
        $other = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();

        $result = app(DailyContractAcceptAction::class)->handle($player, $other, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_city'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered)
            ->and($home->id)->not->toBe($other->id);
    });

    it('rejects accept when status is not offered', function (): void {
        $player = cityDone(97012, 'DailyAccDup');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $result = app(DailyContractAcceptAction::class)->handle($player->fresh(), $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_offered'));
    });

    it('rejects accept for another players contract', function (): void {
        $owner = cityDone(97013, 'DailyOwner');
        $intruder = cityDone(97014, 'DailyThief');
        $offer = dailyOffer($owner);

        $result = app(DailyContractAcceptAction::class)->handle($intruder, dailyCity($owner), $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_found'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });

    it('rejects accept when board date is stale', function (): void {
        $player = cityDone(97015, 'DailyAccStale');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        $offer->board_date = Date::now(config('app.timezone'))->subDay();
        $offer->save();

        $result = app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.stale'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });

    it('allows accepts split across cities up to the daily cap', function (): void {
        $player = dailyPlayer(97016, 'DailyCapSplit');
        $home = dailyCity($player);
        $homeOffers = dailyService()->ensureOffers($player, $home);

        $first = app(DailyContractAcceptAction::class)->handle($player, $home, $homeOffers[0]->id);
        $second = app(DailyContractAcceptAction::class)->handle($player->fresh(), $home, $homeOffers[1]->id);

        expect($first->ok)->toBeTrue()
            ->and($second->ok)->toBeTrue();

        $player = placeInCity($player->fresh(), City::KEY_ELDWOOD);
        $away = dailyCity($player);
        $awayOffer = dailyService()->ensureOffers($player, $away)->first();

        expect($awayOffer)->toBeInstanceOf(CharacterDailyContract::class)
            ->and($away->id)->not->toBe($home->id);

        $third = app(DailyContractAcceptAction::class)->handle($player, $away, $awayOffer->id);

        expect($third->ok)->toBeTrue()
            ->and($awayOffer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
    });

    it('rejects accept over the global daily cap even in another city', function (): void {
        $player = dailyPlayer(97017, 'DailyCapBlock');
        $home = dailyCity($player);
        $homeOffers = dailyService()->ensureOffers($player, $home);
        $cap = gameConfig()->dailyContracts()['max_accepts_per_day'];
        $accepted = 0;

        foreach ($homeOffers as $row) {
            if ($accepted >= $cap) {
                break;
            }

            $ok = app(DailyContractAcceptAction::class)->handle($player->fresh(), $home, $row->id);
            expect($ok->ok)->toBeTrue();
            $accepted++;
        }

        expect($accepted)->toBe($cap);

        $player = placeInCity($player->fresh(), City::KEY_ELDWOOD);
        $away = dailyCity($player);
        $awayOffer = dailyService()->ensureOffers($player, $away)->first();

        expect($awayOffer)->toBeInstanceOf(CharacterDailyContract::class);

        $blocked = app(DailyContractAcceptAction::class)->handle($player, $away, $awayOffer->id);

        expect($blocked->ok)->toBeFalse()
            ->and($blocked->error)->toBe(__('telegram.daily.error.accept_limit'))
            ->and($awayOffer->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });

    it('counts ready and completed toward the global daily cap', function (): void {
        $player = dailyPlayer(97018, 'DailyCapCount');
        $home = dailyCity($player);
        $offers = dailyService()->ensureOffers($player, $home);

        $offers[0]->status = DailyContractStatusEnum::Ready;
        $offers[0]->save();
        $offers[1]->status = DailyContractStatusEnum::Completed;
        $offers[1]->save();

        $ok = app(DailyContractAcceptAction::class)->handle($player, $home, $offers[2]->id);
        expect($ok->ok)->toBeTrue();

        $player = placeInCity($player->fresh(), City::KEY_ELDWOOD);
        $away = dailyCity($player);
        $awayOffer = dailyService()->ensureOffers($player, $away)->first();

        expect($awayOffer)->toBeInstanceOf(CharacterDailyContract::class);

        $blocked = app(DailyContractAcceptAction::class)->handle($player, $away, $awayOffer->id);

        expect($blocked->ok)->toBeFalse()
            ->and($blocked->error)->toBe(__('telegram.daily.error.accept_limit'));
    });
});

describe('startHunt', function (): void {
    it('starts a QUEST_DAILY fight linked to the contract', function (): void {
        $player = cityDone(97020, 'DailyHunt');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        expect($result->ok)->toBeTrue();

        $fight = Fight::query()->findOrFail($player->tg_id);

        expect($fight->kind)->toBe(FightKindEnum::QUEST_DAILY)
            ->and($fight->daily_contract_id)->toBe($offer->id)
            ->and($fight->enemy['catalogId'] ?? null)->toBe($offer->enemy_catalog_id);
    });

    it('rejects hunt when contract is still offered', function (): void {
        $player = cityDone(97021, 'DailyHuntOff');
        $city = dailyCity($player);
        $offer = dailyOffer($player);

        $result = app(DailyContractStartHuntAction::class)->handle($player, $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_accepted'))
            ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    });

    it('rejects hunt with no hp', function (): void {
        $player = cityDone(97022, 'DailyHuntHp');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);
        $player->current_hp = 0;
        $player->save();

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.location.error.no_hp'))
            ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    });

    it('rejects hunt from another city', function (): void {
        $player = cityDone(97023, 'DailyHuntCity');
        $home = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $home, $offer->id);
        $other = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $other, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_city'));
    });
});

describe('markReady and turnIn', function (): void {
    it('marks ready on win and pays rewards on turn-in', function (): void {
        $player = dailyPlayer(97030, 'DailyReady');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);
        app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        $fight = Fight::query()->findOrFail($player->tg_id);
        $silverBefore = $player->fresh()->silver;
        $expBefore = $player->fresh()->exp;

        $ready = app(DailyContractMarkReadyAction::class)->handle($player->fresh(), $fight);

        expect($ready->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Ready);

        $turnIn = app(DailyContractTurnInAction::class)->handle(
            $player->fresh(),
            $city,
            $offer->id,
            NpcEnum::Buyer,
        );

        $fresh = $player->fresh();

        expect($turnIn->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and($fresh->silver)->toBe($silverBefore + $offer->reward_silver)
            ->and($fresh->exp)->toBe($expBefore + $offer->reward_exp);
    });

    it('rejects mark ready when contract is not accepted', function (): void {
        $player = cityDone(97031, 'DailyReadyBad');
        $offer = dailyOffer($player);
        $fight = new Fight;
        $fight->tg_id = $player->tg_id;
        $fight->daily_contract_id = $offer->id;

        $result = app(DailyContractMarkReadyAction::class)->handle($player, $fight);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_accepted'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });

    it('rejects turn-in when trophy is not ready', function (): void {
        $player = cityDone(97032, 'DailyTurnOff');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $result = app(DailyContractTurnInAction::class)->handle(
            $player->fresh(),
            $city,
            $offer->id,
            NpcEnum::Buyer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_ready'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
    });

    it('rejects turn-in for wrong issuer', function (): void {
        $player = cityDone(97033, 'DailyIssuer');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);
        $offer->status = DailyContractStatusEnum::Ready;
        $offer->save();

        $result = app(DailyContractTurnInAction::class)->handle(
            $player->fresh(),
            $city,
            $offer->id,
            NpcEnum::Healer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_issuer'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Ready);
    });

    it('rejects turn-in from another city', function (): void {
        $player = cityDone(97034, 'DailyTurnCity');
        $home = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $home, $offer->id);
        $offer->status = DailyContractStatusEnum::Ready;
        $offer->save();
        $other = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();

        $result = app(DailyContractTurnInAction::class)->handle(
            $player->fresh(),
            $other,
            $offer->id,
            NpcEnum::Buyer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_city'));
    });
});

describe('delivery', function (): void {
    it('does not put parcel in the bag until accept is confirmed', function (): void {
        $player = dailyPlayer(97059, 'DailyDelNoBag');
        $offer = dailyDeliveryOffer($player);

        expect($offer->status)->toBe(DailyContractStatusEnum::Offered)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();

        cityFlowCallback($player->tg_id, 9, 'daily:offer:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();
        assertCityEditHas(mb_trim(__('telegram.daily.card_delivery', [
            'item' => $offer->params['item_name'] ?? 'daily_parcel',
            'city' => $offer->params['city_name'] ?? '',
            'npc' => __('telegram.daily.npc.' . $offer->issuer_npc->value),
            'place' => __('telegram.daily.place.' . $offer->issuer_npc->deliverySpot()->value),
            'silver' => $offer->reward_silver,
            'exp' => $offer->reward_exp,
        ])));

        cityFlowCallback($player->tg_id, 10, 'daily:accept:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeTrue();
    });

    it('grants parcel on accept and pays rewards on turn-in in the target city', function (): void {
        $player = dailyPlayer(97060, 'DailyDelOk');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player);
        $target = City::query()->findOrFail($offer->target_city_id);
        $silverBefore = $player->silver;
        $expBefore = $player->exp;

        expect($offer->target_city_id)->not->toBe($board->id)
            ->and($offer->status)->toBe(DailyContractStatusEnum::Offered)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();

        $accept = app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);

        expect($accept->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeTrue()
            ->and(dailyService()->turnInableForIssuer($player->fresh(), $board, NpcEnum::Buyer))->toHaveCount(0);

        $player = placeInCity($player->fresh(), $target->key);
        $turnInable = dailyService()->turnInableForIssuer($player, $target, NpcEnum::Buyer);

        expect($turnInable->contains(fn (CharacterDailyContract $row): bool => $row->id === $offer->id))->toBeTrue();

        $turnIn = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            NpcEnum::Buyer,
        );

        $fresh = $player->fresh();

        expect($turnIn->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse()
            ->and($fresh->silver)->toBe($silverBefore + $offer->reward_silver)
            ->and($fresh->exp)->toBe($expBefore + $offer->reward_exp);
    });

    it('turns in delivery for non-buyer issuers in the target city', function (NpcEnum $issuer, int $tgId, string $nick): void {
        $player = dailyPlayer($tgId, $nick);
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, $issuer);
        $target = City::query()->findOrFail($offer->target_city_id);

        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = placeInCity($player->fresh(), $target->key);

        $turnIn = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            $issuer,
        );

        expect($turnIn->ok)->toBeTrue()
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();
    })->with([
        'healer' => [NpcEnum::Healer, 97061, 'DailyDelHeal'],
        'blacksmith' => [NpcEnum::Blacksmith, 97062, 'DailyDelSmith'],
        'overseer' => [NpcEnum::Overseer, 97063, 'DailyDelOver'],
        'barkeeper' => [NpcEnum::Barkeeper, 97066, 'DailyDelBark'],
    ]);

    it('rejects accept when bag is full', function (): void {
        $player = dailyPlayer(97064, 'DailyDelFull');
        $player->bag_max_rows = 1;
        $player->save();
        $player = grantGem($player->fresh(), 'ruby_0', 1);
        $city = dailyCity($player);
        $offer = dailyDeliveryOffer($player);

        $result = app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.bag_full'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();
    });

    it('rejects turn-in when parcel is missing', function (): void {
        $player = dailyPlayer(97065, 'DailyDelNoItem');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player);
        $target = City::query()->findOrFail($offer->target_city_id);
        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        bag()->removeQuestItem($player->tg_id, 'daily_parcel');
        $player = placeInCity($player->fresh(), $target->key);

        $result = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            NpcEnum::Buyer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.no_parcel'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
    });

    it('rejects turn-in while still offered', function (): void {
        $player = dailyPlayer(97066, 'DailyDelOff');
        $offer = dailyDeliveryOffer($player);
        $target = City::query()->findOrFail($offer->target_city_id);
        $player = placeInCity($player, $target->key);

        $result = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            NpcEnum::Buyer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_ready'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });

    it('rejects turn-in for wrong issuer', function (): void {
        $player = dailyPlayer(97067, 'DailyDelIssuer');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Buyer);
        $target = City::query()->findOrFail($offer->target_city_id);
        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $player = placeInCity($player->fresh(), $target->key);

        $result = app(DailyContractTurnInAction::class)->handle(
            $player,
            $target,
            $offer->id,
            NpcEnum::Healer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_issuer'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeTrue();
    });

    it('rejects turn-in in the board city instead of the target', function (): void {
        $player = dailyPlayer(97068, 'DailyDelCity');
        $home = dailyCity($player);
        $offer = dailyDeliveryOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $home, $offer->id);

        $result = app(DailyContractTurnInAction::class)->handle(
            $player->fresh(),
            $home,
            $offer->id,
            NpcEnum::Buyer,
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.wrong_city'))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
    });

    it('rejects start hunt on a delivery contract', function (): void {
        $player = dailyPlayer(97069, 'DailyDelHunt');
        $city = dailyCity($player);
        $offer = dailyDeliveryOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.daily.error.not_accepted'))
            ->and(Fight::query()->where('tg_id', $player->tg_id)->exists())->toBeFalse();
    });

    it('strips parcel when an accepted delivery expires', function (): void {
        $player = dailyPlayer(97070, 'DailyDelExp');
        $city = dailyCity($player);
        $offer = dailyDeliveryOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        expect(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeTrue();

        $offer->board_date = Date::now(config('app.timezone'))->subDay();
        $offer->save();

        app(DailyContractExpireStaleAction::class)->handle($player->tg_id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Expired)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse();
    });

    it('marks destination ruins healer with daily mark and hides from tavern', function (): void {
        $player = dailyPlayer(97071, 'DailyDelMark');
        $board = dailyCity($player);
        $offer = dailyDeliveryOffer($player, NpcEnum::Healer);
        app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
        $target = City::query()->findOrFail($offer->target_city_id);
        $player = placeInCity($player->fresh(), $target->key);

        cityFlowCallback($player->tg_id, 9, 'city:tavern');
        Http::assertNotSent(function ($request): bool {
            return str_contains((string) $request->body(), 'city:healer');
        });

        cityFlowCallback($player->tg_id, 10, 'city:ruins');
        assertCityEditMarkupHas(__('telegram.daily.npc_mark', [
            'name' => __('telegram.btn.healer'),
        ]));
        assertCityEditMarkupHas('city:healer');
    });
});

describe('expireStale', function (): void {
    it('expires yesterday accepted and ready contracts without touching offered', function (): void {
        $player = cityDone(97040, 'DailyExp');
        $city = dailyCity($player);
        $offers = dailyService()->ensureOffers($player, $city);

        $accepted = $offers[0];
        $ready = $offers[1];
        $offered = $offers[2];

        $accepted->status = DailyContractStatusEnum::Accepted;
        $accepted->board_date = Date::now(config('app.timezone'))->subDay();
        $accepted->save();

        $ready->status = DailyContractStatusEnum::Ready;
        $ready->board_date = Date::now(config('app.timezone'))->subDay();
        $ready->save();

        $offered->board_date = Date::now(config('app.timezone'))->subDay();
        $offered->save();

        app(DailyContractExpireStaleAction::class)->handle($player->tg_id);

        expect($accepted->fresh()->status)->toBe(DailyContractStatusEnum::Expired)
            ->and($ready->fresh()->status)->toBe(DailyContractStatusEnum::Expired)
            ->and($offered->fresh()->status)->toBe(DailyContractStatusEnum::Offered);
    });
});

describe('telegram surface', function (): void {
    it('shows empty board before quest phase Done', function (): void {
        $player = cityArrivedSkipped(97050, 'DailyPreDone');
        $player->unlock_board = true;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:board');

        assertCityEditHas(mb_trim(__('telegram.location.board_empty')));
        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return (str_contains($request->url(), '/editMessageMedia')
                    || str_contains($request->url(), '/editMessageCaption')
                    || str_contains($request->url(), '/editMessageText'))
                && ! str_contains($body, 'daily:offer:');
        });
    });

    it('puts monster and zone on hunt board rows', function (): void {
        $player = cityDone(97054, 'DailyRowHunt');
        $offer = dailyOffer($player);
        $offer->issuer_npc = NpcEnum::Healer;
        $offer->save();

        cityFlowCallback($player->tg_id, 9, 'city:board');

        assertCityEditMarkupHas(__('telegram.daily.row', [
            'monster' => $offer->params['monster_name'] ?? $offer->enemy_catalog_id,
            'zone' => __('telegram.daily.zone_name.forest'),
        ]));
    });

    it('puts item and city on delivery board rows', function (): void {
        $player = cityDone(97055, 'DailyRowDel');
        $offer = dailyDeliveryOffer($player, NpcEnum::Blacksmith);
        $cityName = $offer->params['city_name'] ?? '';

        cityFlowCallback($player->tg_id, 9, 'city:board');

        assertCityEditMarkupHas(__('telegram.daily.row_delivery', [
            'item' => $offer->params['item_name'] ?? 'daily_parcel',
            'city' => $cityName,
        ]));
    });

    it('mentions issuer npc after daily hunt win', function (): void {
        $player = dailyPlayer(97056, 'DailyWinNpc');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        $offer->issuer_npc = NpcEnum::Healer;
        $offer->save();
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);
        app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        $fight = Fight::query()->findOrFail($player->tg_id);
        $enemy = $fight->enemy;
        $enemy['current_hp'] = 0;
        $fight->enemy = $enemy;
        $fight->player_hp = 10;
        $fight->player_stamina = 12;
        $fight->save();

        $result = app(FightEndService::class)->finishWin($player->fresh(), $fight);

        expect($result->editText)->toContain(mb_trim(__('telegram.daily.win_ready', [
            'npc' => __('telegram.daily.npc.healer'),
        ])))
            ->and($offer->fresh()->status)->toBe(DailyContractStatusEnum::Ready);
    });

    it('accepts from board then shows hunt in forest without duplicate pve', function (): void {
        $player = cityDone(97051, 'DailyTgHunt');
        $offer = dailyOffer($player);

        cityFlowCallback($player->tg_id, 9, 'daily:accept:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted);
        assertCityEditHas(mb_trim(__('telegram.daily.accept_done', [
            'zone' => __('telegram.daily.zone.forest'),
            'npc' => __('telegram.daily.npc.buyer'),
        ])));

        cityFlowCallback($player->tg_id, 10, 'city:board');
        Http::assertSent(function ($request) use ($offer): bool {
            $body = $request->body();

            return (str_contains($request->url(), '/editMessageMedia')
                    || str_contains($request->url(), '/editMessageCaption')
                    || str_contains($request->url(), '/editMessageText'))
                && str_contains($body, 'daily:offer:')
                && ! str_contains($body, 'daily:offer:' . $offer->id);
        });

        cityFlowCallback($player->tg_id, 11, 'city:forest');

        assertCityEditMarkupHas('daily:hunt:' . $offer->id);
        Http::assertSent(function ($request) use ($offer): bool {
            $body = $request->body();

            return (str_contains($request->url(), '/editMessageMedia')
                    || str_contains($request->url(), '/editMessageCaption')
                    || str_contains($request->url(), '/editMessageText'))
                && str_contains($body, 'daily:hunt:' . $offer->id)
                && ! str_contains($body, 'fight:start:' . $offer->enemy_catalog_id);
        });
    });

    it('shows buyer turn-in when ready and completes on give', function (): void {
        $player = dailyPlayer(97052, 'DailyTgTurn');
        $city = dailyCity($player);
        $offer = dailyOffer($player);
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);
        $offer->status = DailyContractStatusEnum::Ready;
        $offer->save();

        $silverBefore = $player->fresh()->silver;

        cityFlowCallback($player->tg_id, 9, 'city:buyer');
        assertCityEditMarkupHas('daily:buyer:list');

        cityFlowCallback($player->tg_id, 10, 'daily:buyer:list');
        assertCityEditMarkupHas('daily:buyer:turnin:' . $offer->id);
        assertCityEditMarkupHas('"style":"success"');
        assertCityEditMarkupHas('"style":"danger"');

        cityFlowCallback($player->tg_id, 11, 'daily:buyer:turnin:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and($player->fresh()->silver)->toBe($silverBefore + $offer->reward_silver);
        assertCityEditHas('🪙 +' . $offer->reward_silver);
    });

    it('accepts delivery from board and completes turn-in at healer in target city', function (): void {
        $player = dailyPlayer(97053, 'DailyTgDel');
        $offer = dailyDeliveryOffer($player, NpcEnum::Healer);
        $target = City::query()->findOrFail($offer->target_city_id);

        cityFlowCallback($player->tg_id, 9, 'daily:accept:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Accepted)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeTrue();
        assertCityEditHas(mb_trim(__('telegram.daily.accept_done_delivery', [
            'npc' => __('telegram.daily.npc.healer'),
            'city' => $target->name,
            'place' => __('telegram.daily.place.ruins'),
        ])));

        $player = placeInCity($player->fresh(), $target->key);
        $silverBefore = $player->silver;

        cityFlowCallback($player->tg_id, 10, 'city:ruins');
        assertCityEditMarkupHas('city:healer');

        cityFlowCallback($player->tg_id, 11, 'city:healer');
        assertCityEditMarkupHas('daily:healer:list');
        assertCityEditMarkupHas('city:ruins');

        cityFlowCallback($player->tg_id, 12, 'daily:healer:list');
        assertCityEditMarkupHas('daily:healer:turnin:' . $offer->id);

        cityFlowCallback($player->tg_id, 13, 'daily:healer:turnin:' . $offer->id);

        expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
            ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse()
            ->and($player->fresh()->silver)->toBe($silverBefore + $offer->reward_silver);
        assertCityEditHas('🪙 +' . $offer->reward_silver);
    });
});
