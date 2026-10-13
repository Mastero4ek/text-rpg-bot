<?php

declare(strict_types=1);

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Actions\DailyContract\DailyContractStartHuntAction;
use App\Enums\City\PveZoneEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\LocationEnum;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Fight;
use App\Queries\City\CityQuery;
use App\Services\Quest\QuestUnlockService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

describe('gates and unlock', function (): void {
    it('lists every pve zone button on gates when city has all flags', function (): void {
        $player = cityDone(98001, 'PveGatesAll');

        cityFlowCallback($player->tg_id, 9, 'city:gates');

        assertCityEditMarkupHas('city:forest');
        assertCityEditMarkupHas('city:swamp');
        assertCityEditMarkupHas('city:ruins');
        assertCityEditMarkupHas('city:port');
        assertCityEditHas(mb_trim(__('telegram.location.gates')));
    });

    it('hides zone button when city flag is off', function (): void {
        $player = cityDone(98002, 'PveNoSwamp', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_swamp = false;
        $city->save();

        cityFlowCallback($player->tg_id, 9, 'city:gates');

        assertCityEditMarkupHas('city:forest');
        Http::assertSent(function (Request $request): bool {
            if (
                ! str_contains($request->url(), '/editMessageMedia')
                && ! str_contains($request->url(), '/editMessageCaption')
                && ! str_contains($request->url(), '/editMessageText')
            ) {
                return false;
            }

            $markup = $request['reply_markup'] ?? $request->body();

            return is_string($markup)
                && str_contains($markup, 'city:forest')
                && ! str_contains($markup, 'city:swamp');
        });
    });

    it('fogs swamp and port until city_unlocked_full while ruins need unlock_ruins', function (): void {
        $player = cityArrivedSkipped(98003, 'PveFogZones');
        $player->progress_step = ProgressStepEnum::DONE;
        $player->overseer_trained = true;
        $player->unlock_forest = true;
        $player->unlock_portal = true;
        $player->unlock_ruins = false;
        $player->city_unlocked_full = false;
        $player->save();
        $player->loadMissing('city');

        $unlocks = app(QuestUnlockService::class);

        expect($unlocks->canOpen($player, LocationEnum::Forest))->toBeTrue()
            ->and($unlocks->canOpen($player, LocationEnum::Swamp))->toBeFalse()
            ->and($unlocks->canOpen($player, LocationEnum::Ruins))->toBeFalse()
            ->and($unlocks->canOpen($player, LocationEnum::Port))->toBeFalse();

        cityFlowCallback($player->tg_id, 9, 'city:gates');
        assertCityEditMarkupHas('city:swamp');
        assertCityEditMarkupHas('🔒');

        $cityEscaped = TelegramHtml::escape($player->city->name);

        cityFlowCallback($player->tg_id, 10, 'city:swamp');
        assertCityEditHas($cityEscaped);
        assertCityEditMarkupHas('city:gates');

        cityFlowCallback($player->tg_id, 11, 'city:ruins');
        assertCityEditHas($cityEscaped);
        assertCityEditMarkupHas('city:gates');

        cityFlowCallback($player->tg_id, 12, 'city:port');
        assertCityEditHas($cityEscaped);
        assertCityEditMarkupHas('city:gates');
    });

    it('opens swamp after city_unlocked_full', function (): void {
        $player = cityDone(98004, 'PveUnlockSw');

        expect(app(QuestUnlockService::class)->canOpen($player, LocationEnum::Swamp))->toBeTrue();

        cityFlowCallback($player->tg_id, 9, 'city:swamp');

        assertCityEditHas(mb_trim(__('telegram.location.swamp')));
        assertCityEditHas('swamp.png');
        assertCityEditMarkupHas('fight:start:swamp_witch');
    });

    it('sends zone art for swamp ruins and port', function (int $tgId, string $nick, string $callback, string $image): void {
        $player = cityDone($tgId, $nick);

        cityFlowCallback($player->tg_id, 9, $callback);

        assertCityEditHas($image);
    })->with([
        [98005, 'PveArtSw', 'city:swamp', 'swamp.png'],
        [98006, 'PveArtRu', 'city:ruins', 'ruins.png'],
        [98007, 'PveArtPo', 'city:port', 'port.png'],
    ]);
});

describe('zone catalogs and fights', function (): void {
    it('lists seeded enemies only in their zones', function (
        int $tgId,
        string $nick,
        string $callback,
        string $catalogId,
        string $locationKey,
        string $foreignCatalogId,
    ): void {
        $player = cityDone($tgId, $nick);

        cityFlowCallback($player->tg_id, 9, $callback);

        assertCityEditHas(mb_trim(__('telegram.location.' . $locationKey)));
        assertCityEditMarkupHas('fight:start:' . $catalogId);
        Http::assertSent(function (Request $request) use ($catalogId, $foreignCatalogId): bool {
            if (
                ! str_contains($request->url(), '/editMessageMedia')
                && ! str_contains($request->url(), '/editMessageCaption')
                && ! str_contains($request->url(), '/editMessageText')
            ) {
                return false;
            }

            $markup = $request['reply_markup'] ?? '';

            if (! is_string($markup) || $markup === '') {
                $markup = $request->body();
            }

            return str_contains($markup, 'fight:start:' . $catalogId)
                && ! str_contains($markup, 'fight:start:' . $foreignCatalogId);
        });
    })->with([
        [98011, 'PvePickFor', 'city:forest', 'forest_bandit', 'forest', 'swamp_witch'],
        [98012, 'PvePickSw', 'city:swamp', 'swamp_witch', 'swamp', 'forest_bandit'],
        [98013, 'PvePickRu', 'city:ruins', 'wild_dog', 'ruins', 'forest_bandit'],
        [98014, 'PvePickPo', 'city:port', 'rabid_rat', 'port', 'forest_bandit'],
    ]);

    it('rejects zone open when city flag is missing', function (
        int $tgId,
        string $nick,
        string $callback,
        string $errorKey,
    ): void {
        $player = cityDone($tgId, $nick, City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_swamp = false;
        $city->has_ruins = false;
        $city->has_port = false;
        $city->save();

        cityFlowCallback($player->tg_id, 9, $callback);

        assertCityEditHas(mb_trim(__($errorKey)));
    })->with([
        [98021, 'PveNoSw', 'city:swamp', 'telegram.location.error.no_swamp'],
        [98022, 'PveNoRu', 'city:ruins', 'telegram.location.error.no_ruins'],
        [98023, 'PveNoPo', 'city:port', 'telegram.location.error.no_port'],
    ]);

    it('starts fight with return_to matching enemy zone', function (
        int $tgId,
        string $nick,
        string $catalogId,
        FightReturnEnum $returnTo,
    ): void {
        $player = cityDone($tgId, $nick);

        $update = new TelegramUpdate([
            'update_id' => $player->tg_id,
            'callback_query' => [
                'id' => 'cb-' . $player->tg_id,
                'data' => 'fight:start:' . $catalogId,
                'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
                'message' => [
                    'message_id' => 9,
                    'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                    'text' => 'fight',
                ],
            ],
        ]);

        app(FightHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        $fight = fights()->findByTgId($player->tg_id);
        $player->refresh();

        expect($fight->return_to)->toBe($returnTo)
            ->and($player->fight_return)->toBe($returnTo)
            ->and(fights()->enemy($fight)->catalogId)->toBe($catalogId);
    })->with([
        [98031, 'PveFtFor', 'forest_bandit', FightReturnEnum::Forest],
        [98032, 'PveFtSw', 'swamp_witch', FightReturnEnum::Swamp],
        [98033, 'PveFtRu', 'wild_dog', FightReturnEnum::Ruins],
        [98034, 'PveFtPo', 'rabid_rat', FightReturnEnum::Port],
    ]);

    it('rejects fight start for enemy outside city zone pivots', function (): void {
        $player = cityDone(98040, 'PveWrongPv', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_swamp = true;
        $city->save();

        $witch = EnemyCatalog::query()->findOrFail('swamp_witch');
        $witch->swampCities()->detach($city->id);

        expect(app(CityQuery::class)->findZoneForCatalog($city->id, 'swamp_witch'))->toBeNull();

        $update = new TelegramUpdate([
            'update_id' => $player->tg_id,
            'callback_query' => [
                'id' => 'cb-' . $player->tg_id,
                'data' => 'fight:start:swamp_witch',
                'from' => ['id' => $player->tg_id, 'is_bot' => false, 'first_name' => 'A'],
                'message' => [
                    'message_id' => 9,
                    'chat' => ['id' => $player->tg_id, 'type' => 'private'],
                    'text' => 'fight',
                ],
            ],
        ]);

        app(FightHandler::class)->handleCallback(
            $update,
            new TelegramResponder(app(TelegramClient::class), $update),
        );

        expect(fights()->exists($player->tg_id))->toBeFalse();
        assertCityEditHas(mb_trim(__('telegram.location.error.enemy_not_found')));
    });
});

describe('daily hunt zones', function (): void {
    it('shows hunt only in the contract zone and not in another', function (): void {
        $player = cityDone(98050, 'PveHuntZn');
        $city = dailyCity($player);
        $offer = dailyOffer($player, 'swamp');
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        cityFlowCallback($player->tg_id, 9, 'city:swamp');
        assertCityEditMarkupHas('daily:hunt:' . $offer->id);
        Http::assertSent(function (Request $request) use ($offer): bool {
            if (
                ! str_contains($request->url(), '/editMessageMedia')
                && ! str_contains($request->url(), '/editMessageCaption')
                && ! str_contains($request->url(), '/editMessageText')
            ) {
                return false;
            }

            $markup = $request['reply_markup'] ?? '';

            if (! is_string($markup) || $markup === '') {
                $markup = $request->body();
            }

            return str_contains($markup, 'daily:hunt:' . $offer->id)
                && ! str_contains($markup, 'fight:start:' . $offer->enemy_catalog_id);
        });

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        cityFlowCallback($player->tg_id, 10, 'city:forest');
        assertCityEditMarkupHas('fight:start:forest_bandit');
        Http::assertSent(function (Request $request) use ($offer): bool {
            if (
                ! str_contains($request->url(), '/editMessageMedia')
                && ! str_contains($request->url(), '/editMessageCaption')
                && ! str_contains($request->url(), '/editMessageText')
            ) {
                return false;
            }

            $markup = $request['reply_markup'] ?? '';

            if (! is_string($markup) || $markup === '') {
                $markup = $request->body();
            }

            return str_contains($markup, 'fight:start:forest_bandit')
                && ! str_contains($markup, 'daily:hunt:' . $offer->id);
        });
    });

    it('starts daily hunt with return_to from params.zone', function (): void {
        $player = cityDone(98051, 'PveHuntRt');
        $city = dailyCity($player);
        $offer = dailyOffer($player, 'ruins');
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city, $offer->id);

        expect($result->ok)->toBeTrue();

        $fight = Fight::query()->findOrFail($player->tg_id);

        expect($fight->return_to)->toBe(FightReturnEnum::Ruins)
            ->and($fight->daily_contract_id)->toBe($offer->id);
    });

    it('rejects daily hunt when city lost the contract zone', function (): void {
        $player = cityDone(98052, 'PveHuntNz');
        $city = dailyCity($player);
        $offer = dailyOffer($player, 'port');
        app(DailyContractAcceptAction::class)->handle($player, $city, $offer->id);

        $city->has_port = false;
        $city->save();

        $result = app(DailyContractStartHuntAction::class)->handle($player->fresh(), $city->fresh(), $offer->id);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(__('telegram.location.error.no_port'))
            ->and(Fight::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    });

    it('materializes only hunt pool rows for zones the city has', function (): void {
        $player = cityDone(98053, 'PveHuntPl', City::KEY_THORNBREAK);
        $city = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
        $city->has_swamp = false;
        $city->has_ruins = false;
        $city->has_port = false;
        $city->has_forest = true;
        $city->save();

        $offers = dailyService()->ensureOffers($player, $city->fresh());

        expect($offers)->toHaveCount(gameConfig()->dailyContracts()['board_slots']);

        foreach ($offers as $row) {
            if ($row->type === DailyContractTypeEnum::Delivery) {
                expect($row->enemy_catalog_id)->toBeNull()
                    ->and($row->bag_catalog_id)->not->toBeNull();

                continue;
            }

            $zone = PveZoneEnum::tryFrom((string) ($row->params['zone'] ?? ''));

            expect($zone)->toBe(PveZoneEnum::Forest)
                ->and($row->enemy_catalog_id)->toBe('forest_bandit');
        }
    });
});
