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

it('opens healer offer with heal and potions callbacks', function (): void {
    $player = cityDone(9701, 'HealerOffer');

    cityFlowCallback($player->tg_id, 9, 'city:healer');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.offer')));
    assertCityEditMarkupHas('city:healer:heal');
    assertCityEditMarkupHas('city:healer:potions');
    assertCityEditHas('healer_tavern.png');
});

it('heals through telegram and shows done copy', function (): void {
    $player = cityDone(9702, 'HealerHealOk');
    $player->gold = 1;
    $player->current_hp = 1;
    $player->current_stamina = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer:heal');

    $player->refresh();
    expect($player->gold)->toBe(0)
        ->and($player->current_hp)->toBe(characters()->maxHp($player))
        ->and($player->current_stamina)->toBe(characters()->maxStamina($player));

    assertCityEditHas(mb_trim(__('telegram.npc.healer.done')));
    assertCityEditMarkupHas('city:healer');
});

it('shows already full error on heal callback', function (): void {
    $player = cityDone(9703, 'HealerFull');
    $player->gold = 1;
    $player->current_hp = characters()->maxHp($player);
    $player->current_stamina = characters()->maxStamina($player);
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer:heal');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.error.already_full')));
    assertCityEditMarkupHas('city:healer');
});

it('shows not enough gold error on heal callback', function (): void {
    $player = cityDone(9704, 'HealerPoor');
    $player->gold = 0;
    $player->current_hp = 1;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer:heal');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.error.not_enough_gold')));
    assertCityEditMarkupHas('city:healer');
});

it('lists city potions on shelf screen', function (): void {
    $player = cityDone(9705, 'HealerShelf');

    cityFlowCallback($player->tg_id, 9, 'city:healer:potions');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.potions')));
    assertCityEditMarkupHas('city:healer:potion:heal_0');
    assertCityEditMarkupHas('city:healer:potion:stamina_0');
    assertCityEditHas('15🪙 · ');
});

it('buys potion through telegram and keeps shelf markup', function (): void {
    $player = cityDone(9706, 'HealerBuyOk');
    $price = bagCatalog()->findPotion('heal_0')->price;
    $player->silver = $price;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer:potion:heal_0');

    expect(bag()->potionCountByProfile($player->tg_id, ProfileEnum::HEAL))->toBe(1);
    assertCityEditHas(mb_trim(__('telegram.npc.healer.bought_potion', [
        'name' => bagCatalog()->findPotion('heal_0')->name,
    ])));
    assertCityEditMarkupHas('city:healer:potion:heal_0');
});

it('shows not enough silver narrative on potion buy', function (): void {
    $player = cityDone(9707, 'HealerBuyPoor');
    $player->silver = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:healer:potion:heal_0');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.error.not_enough_silver')));
    assertCityEditMarkupHas('city:healer:potion:heal_0');
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

it('shows empty shelf copy when city has no potion pivot', function (): void {
    $player = cityDone(9709, 'HealerEmptyShelf', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->bagCatalog()->sync([]);
    bagCatalog()->forgetCache();

    cityFlowCallback($player->tg_id, 9, 'city:healer:potions');

    assertCityEditHas(mb_trim(__('telegram.npc.healer.potions_empty')));
    assertCityEditMarkupHas('city:healer');
});
