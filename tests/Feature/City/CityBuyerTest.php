<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Services\Bag\BagService;

describe('buyer gem buy', function (): void {
    it('buys gem when city has buyer and pivot', function (): void {
        $p = placeInCity(characters()->createDraft(9401), City::KEY_ANKRAT);
        $price = bagCatalog()->findGem('ruby_0')->price;
        $p->silver = $price;
        $p->save();

        $res = app(BagService::class)->buy($p, 'ruby_0');

        expect($res->ok)->toBeTrue()
            ->and(bag()->looseGems($res->character))->toHaveCount(1)
            ->and($res->character->silver)->toBe(0);
    });

    it('rejects when silver is short', function (): void {
        $p = placeInCity(characters()->createDraft(9402), City::KEY_ANKRAT);
        $p->silver = 0;
        $p->save();

        $res = app(BagService::class)->buy($p, 'ruby_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.not_enough_silver'));
    });

    it('rejects when gold is short for gold gem', function (): void {
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $gem = BagCatalog::factory()->create([
            'name' => 'Золотой камень домена',
            'price' => 2,
            'currency' => CurrencyEnum::GOLD,
            'enabled' => true,
        ]);
        $gem->cities()->sync([$city->id]);
        bagCatalog()->forgetCache();

        $p = placeInCity(characters()->createDraft(9403), City::KEY_ANKRAT);
        $p->gold = 0;
        $p->silver = 999;
        $p->save();

        $res = app(BagService::class)->buy($p, $gem->catalog_id);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.not_enough_gold'));
    });

    it('rejects when bag is full', function (): void {
        $p = placeInCity(characters()->createDraft(9404), City::KEY_ANKRAT);
        $p->bag_max_rows = 1;
        $p->silver = 999;
        $p->save();
        $p = grantGem($p, 'ruby_0', 1);

        $res = app(BagService::class)->buy($p, 'emerald_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.bag_full'));
    });

    it('rejects when gem is missing from city pivot', function (): void {
        $p = placeInCity(characters()->createDraft(9405), City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->detach('ruby_0');
        bagCatalog()->forgetCache();
        $p->silver = 999;
        $p->save();

        $res = app(BagService::class)->buy($p, 'ruby_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.gem_not_in_shop'));
    });

    it('rejects when city has no buyer', function (): void {
        $p = placeInCity(characters()->createDraft(9406), City::KEY_ELDWOOD);
        $p->silver = 999;
        $p->save();

        $res = app(BagService::class)->buy($p, 'ruby_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.gem_not_in_shop'));
    });
});
