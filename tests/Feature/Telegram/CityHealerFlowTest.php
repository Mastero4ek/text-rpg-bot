<?php

declare(strict_types=1);

use App\Enums\Equipment\ProfileEnum;
use App\Models\City;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('opens healer offer with potions above back and heal row', function (): void {
    $player = cityDone(9701, 'HealerOffer');

    cityFlowCallback($player->tg_id, 9, 'city:healer');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.offer')));
    assertCityEditMarkupHas('city:healer:potions');
    assertCityEditMarkupHas('city:healer:heal');
    assertCityEditMarkupHas('city:tavern');
    assertCityEditHas('healer_tavern.png');
    assertTelegramMarkupBody(function (string $body): bool {
        return str_contains($body, 'city:healer:potions')
            && str_contains($body, 'city:healer:heal')
            && str_contains($body, '"style":"success"')
            && str_contains($body, '"style":"danger"')
            && ! str_contains($body, 'city:healer:heal:yes');
    });
});

it('shows no healer error with back to tavern when flag is off', function (): void {
    $player = cityDone(9708, 'HealerGone', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->has_healer = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.error.no_healer')));
    assertCityEditMarkupHas('city:tavern');
});

describe('heal', function (): void {
    it('opens heal confirm with hp stamina and gold without healing', function (): void {
        $player = cityDone(9711, 'HealerHealAsk');
        $player->gold = 5;
        $player->current_hp = 1;
        $player->current_stamina = 0;
        $player->save();
        $maxHp = characters()->maxHp($player);
        $maxStamina = characters()->maxStamina($player);
        $gold = (int) gameConfig()->settings()['hospital']['goldCost'];

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal');

        $player->refresh();
        expect($player->gold)->toBe(5)
            ->and($player->current_hp)->toBe(1)
            ->and($player->current_stamina)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.healer.heal_confirm', [
            'hp' => 1,
            'max_hp' => $maxHp,
            'stamina' => 0,
            'max_stamina' => $maxStamina,
            'gold' => $gold,
        ])));
        assertCityEditMarkupHas('city:healer:heal:yes');
        assertCityEditMarkupHas('city:healer');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('cancels heal confirm and returns to offer without healing', function (): void {
        $player = cityDone(9712, 'HealerHealNo');
        $player->gold = 1;
        $player->current_hp = 1;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:healer');

        $player->refresh();
        expect($player->gold)->toBe(1)
            ->and($player->current_hp)->toBe(1);
        assertCityEditHas(mb_trim(__('telegram.npc.healer.offer')));
        assertCityEditMarkupHas('city:healer:heal');
        assertCityEditMarkupHas('city:healer:potions');
    });

    it('heals through confirm path and deducts gold', function (): void {
        $player = cityDone(9702, 'HealerHealOk');
        $player->gold = 1;
        $player->current_hp = 1;
        $player->current_stamina = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:healer:heal:yes');

        $player->refresh();
        expect($player->gold)->toBe(0)
            ->and($player->current_hp)->toBe(characters()->maxHp($player))
            ->and($player->current_stamina)->toBe(characters()->maxStamina($player));
        assertCityEditHas(mb_trim(__('telegram.npc.healer.done')));
        assertCityEditMarkupHas('city:healer');
    });

    it('shows already full error on heal yes and returns to offer without spending gold', function (): void {
        $player = cityDone(9703, 'HealerFull');
        $player->gold = 1;
        $player->current_hp = characters()->maxHp($player);
        $player->current_stamina = characters()->maxStamina($player);
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal:yes');

        $player->refresh();
        expect($player->gold)->toBe(1)
            ->and($player->current_hp)->toBe(characters()->maxHp($player));
        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.already_full')));
        assertCityEditMarkupHas('city:healer:heal');
        assertCityEditMarkupHas('city:healer:potions');
    });

    it('shows not enough gold error on heal yes and returns to offer without healing', function (): void {
        $player = cityDone(9704, 'HealerPoor');
        $player->gold = 0;
        $player->current_hp = 1;
        $player->current_stamina = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal:yes');

        $player->refresh();
        expect($player->gold)->toBe(0)
            ->and($player->current_hp)->toBe(1)
            ->and($player->current_stamina)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.not_enough_gold')));
        assertCityEditMarkupHas('city:healer:heal');
        assertCityEditMarkupHas('city:healer:potions');
    });

    it('shows no healer on heal confirm when flag is off', function (): void {
        $player = cityDone(9713, 'HealerHealGone', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->has_healer = false;
        $city->save();
        $player->current_hp = 1;
        $player->gold = 1;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.no_healer')));
        assertCityEditMarkupHas('city:tavern');
        expect($player->fresh()->current_hp)->toBe(1);
    });

    it('shows no healer on heal yes when flag is off', function (): void {
        $player = cityDone(9714, 'HealerYesGone', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->has_healer = false;
        $city->save();
        $player->current_hp = 1;
        $player->gold = 1;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:heal:yes');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.no_healer')));
        assertCityEditMarkupHas('city:tavern');
        $player->refresh();
        expect($player->gold)->toBe(1)
            ->and($player->current_hp)->toBe(1);
    });
});

describe('potions', function (): void {
    it('lists city potions on shelf screen without prices', function (): void {
        $player = cityDone(9705, 'HealerShelf');

        cityFlowCallback($player->tg_id, 9, 'city:healer:potions');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.potions')));
        assertCityEditMarkupHas('city:healer:potion:view:heal_0');
        assertCityEditMarkupHas('city:healer:potion:view:stamina_0');
        assertTelegramMarkupBody(function (string $body): bool {
            return ! str_contains($body, '🪙 -');
        });
    });

    it('opens potion card with description price and buy row', function (): void {
        $player = cityDone(9710, 'HealerCard');
        $potion = bagCatalog()->findPotion('heal_0');

        cityFlowCallback($player->tg_id, 9, 'city:healer:potion:view:heal_0');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.potion_card', [
            'name' => $potion->name,
            'description' => $potion->description,
            'effect_mark' => '❤️',
            'effect' => $potion->effectValue,
            'mark' => '🪙',
            'price' => $potion->price,
        ])));
        assertCityEditMarkupHas('city:healer:potions');
        assertCityEditMarkupHas('city:healer:potion:buy:heal_0');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('buys potion through telegram and keeps shelf markup', function (): void {
        $player = cityDone(9706, 'HealerBuyOk');
        $price = bagCatalog()->findPotion('heal_0')->price;
        $player->silver = $price;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:potion:view:heal_0');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:healer:potion:buy:heal_0');

        expect(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(1);
        assertCityEditHas(mb_trim(__('telegram.npc.healer.bought_potion', [
            'name' => bagCatalog()->findPotion('heal_0')->name,
        ])));
        assertCityEditMarkupHas('city:healer:potion:view:heal_0');
    });

    it('shows not enough silver narrative on potion buy and stays on card', function (): void {
        $player = cityDone(9707, 'HealerBuyPoor');
        $player->silver = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:potion:buy:heal_0');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.not_enough_silver')));
        assertCityEditMarkupHas('city:healer:potion:buy:heal_0');
        expect(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(0);
    });

    it('shows bag full narrative on potion buy and stays on card', function (): void {
        $player = cityDone(9715, 'HealerBuyFull');
        $player->bag_max_rows = 1;
        $player->silver = 9999;
        $player->save();
        bag()->addPotion($player->tg_id, 'stamina_0');

        cityFlowCallback($player->tg_id, 9, 'city:healer:potion:buy:heal_0');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.bag_full')));
        assertCityEditMarkupHas('city:healer:potion:buy:heal_0');
        expect(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(0);
    });

    it('shows unavailable narrative when potion is not in city pivot', function (): void {
        $player = cityDone(9716, 'HealerBuyMiss', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->detach('heal_0');
        bagCatalog()->forgetCache();
        $player->silver = 9999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:healer:potion:buy:heal_0');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.error.potion_unavailable')));
        assertCityEditMarkupHas('city:healer:potion:view:stamina_0');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:healer:potion:buy:heal_0'));
        expect(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(0);
    });

    it('shows empty shelf copy when city has no potion pivot', function (): void {
        $player = cityDone(9709, 'HealerEmptyShelf', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->sync([]);
        bagCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:healer:potions');

        assertCityEditHas(mb_trim(__('telegram.npc.healer.potions_empty')));
        assertCityEditMarkupHas('city:healer');
    });
});
