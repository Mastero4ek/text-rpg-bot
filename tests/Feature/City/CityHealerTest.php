<?php

declare(strict_types=1);

use App\Actions\City\CityHealerHealAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;

describe('full heal', function (): void {
    it('fills hp and stamina for gold', function (): void {
        $p = placeInCity(characters()->createDraft(9201), City::KEY_ANKRAT);
        $p->gold = 1;
        $p->current_hp = 1;
        $p->current_stamina = 0;
        $p->save();

        $res = app(CityHealerHealAction::class)->handle($p);

        expect($res->ok)->toBeTrue()
            ->and($res->character->gold)->toBe(0)
            ->and($res->character->current_hp)->toBe(characters()->maxHp($res->character))
            ->and($res->character->current_stamina)->toBe(characters()->maxStamina($res->character));
    });

    it('rejects when gold is short', function (): void {
        $p = placeInCity(characters()->createDraft(9202), City::KEY_ANKRAT);
        $p->gold = 0;
        $p->current_hp = 1;
        $p->save();

        $res = app(CityHealerHealAction::class)->handle($p);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.not_enough_gold'));
    });

    it('rejects when already full after regen', function (): void {
        $p = placeInCity(characters()->createDraft(9203), City::KEY_ANKRAT);
        $p->gold = 1;
        $p->current_hp = characters()->maxHp($p);
        $p->current_stamina = characters()->maxStamina($p);
        $p->save();

        $res = app(CityHealerHealAction::class)->handle($p);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.already_full'));
    });

    it('rejects when city has no healer', function (): void {
        $p = placeInCity(characters()->createDraft(9204), City::KEY_ELDWOOD);
        $p->gold = 1;
        $p->current_hp = 1;
        $p->save();

        $res = app(CityHealerHealAction::class)->handle($p);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.no_healer'));
    });

    it('rejects when character has no city', function (): void {
        $p = characters()->createDraft(9205);
        $p->gold = 1;
        $p->current_hp = 1;
        $p->save();

        $res = app(CityHealerHealAction::class)->handle($p);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.city_unavailable'));
    });
});

describe('healer potions', function (): void {
    it('buys a silver potion from city pivot', function (): void {
        $p = placeInCity(characters()->createDraft(9210), City::KEY_ANKRAT);
        $price = bagCatalog()->findPotion('heal_0')->price;
        $p->silver = $price;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, 'heal_0');

        expect($res->ok)->toBeTrue()
            ->and($res->character->silver)->toBe(0)
            ->and(bag()->potionCountByProfile($p->tg_id, ProfileEnum::HEAL))->toBe(1);
    });

    it('rejects silver potion when silver is short', function (): void {
        $p = placeInCity(characters()->createDraft(9211), City::KEY_ANKRAT);
        $p->silver = 0;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, 'heal_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.not_enough_silver'));
    });

    it('rejects gold potion when gold is short', function (): void {
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $potion = BagCatalog::factory()->potion()->create([
            'name' => 'Золотое зелье теста',
            'price' => 3,
            'currency' => CurrencyEnum::GOLD,
            'enabled' => true,
        ]);
        $potion->cities()->sync([$city->id]);
        bagCatalog()->forgetCache();

        $p = placeInCity(characters()->createDraft(9212), City::KEY_ANKRAT);
        $p->gold = 0;
        $p->silver = 999;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, $potion->catalog_id);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.not_enough_gold_potion'));
    });

    it('rejects when bag is full', function (): void {
        $p = placeInCity(characters()->createDraft(9213), City::KEY_ANKRAT);
        $p->bag_max_rows = 1;
        $p->silver = 999;
        $p->save();
        $p = grantGem($p, 'ruby_0', 1);

        $res = healerService()->buyPotion($p->tg_id, 'heal_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.bag_full'));
    });

    it('rejects potion missing from city pivot', function (): void {
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $potion = BagCatalog::factory()->potion()->create([
            'name' => 'Чужая склянка',
            'price' => 1,
            'currency' => CurrencyEnum::SILVER,
            'enabled' => true,
        ]);
        bagCatalog()->forgetCache();

        expect($potion->cities()->whereKey($city->id)->exists())->toBeFalse();

        $p = placeInCity(characters()->createDraft(9214), City::KEY_ANKRAT);
        $p->silver = 999;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, $potion->catalog_id);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.potion_unavailable'));
    });

    it('rejects when city has no healer', function (): void {
        $p = placeInCity(characters()->createDraft(9215), City::KEY_ELDWOOD);
        $p->silver = 999;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, 'heal_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('telegram.npc.healer.error.no_healer'));
    });

    it('rejects when character has no city', function (): void {
        $p = characters()->createDraft(9216);
        $p->silver = 999;
        $p->save();

        $res = healerService()->buyPotion($p->tg_id, 'heal_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.city_unavailable'));
    });
});
