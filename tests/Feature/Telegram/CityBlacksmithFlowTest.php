<?php

declare(strict_types=1);

use App\Models\City;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('opens blacksmith offer with gear and repair callbacks', function (): void {
    $player = cityDone(9801, 'SmithOffer');

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.offer')));
    assertCityEditMarkupHas('city:blacksmith:gear');
    assertCityEditMarkupHas('city:blacksmith:repair');
    assertCityEditHas('blacksmith_tavern.png');
});

it('lists gear on stand with portal-style rows', function (): void {
    $player = cityDone(9802, 'SmithGearList');

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear')));
    assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
    assertCityEditHas(' · ');
    assertCityEditMarkupHas('city:blacksmith');
});

it('shows empty stand when city has no backpack pivot', function (): void {
    $player = cityDone(9803, 'SmithGearEmpty', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->backpackCatalog()->sync([]);
    shopCatalog()->forgetCache();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear_empty')));
    assertCityEditMarkupHas('city:blacksmith');
});

it('buys gear through telegram and keeps stand markup', function (): void {
    $player = cityDone(9804, 'SmithBuyOk');
    $price = shopCatalog()->findItem('sword_0')->price;
    $player->silver = $price;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

    expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeTrue();
    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.bought', [
        'name' => shopCatalog()->findItem('sword_0')->itemName,
    ])));
    assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
});

it('shows not enough silver narrative on gear buy', function (): void {
    $player = cityDone(9805, 'SmithBuyPoor');
    $player->silver = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_silver')));
    assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
});

it('shows inventory full narrative on gear buy', function (): void {
    $player = cityDone(9806, 'SmithBuyFull');
    $player->backpack_max_rows = 1;
    $player->silver = 9999;
    $player->save();
    backpack()->addItem($player->tg_id, 'hammer_0');

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.inventory_full')));
    assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
});

it('shows unavailable narrative when item is not in city pivot', function (): void {
    $player = cityDone(9807, 'SmithBuyMiss', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->backpackCatalog()->detach('sword_0');
    shopCatalog()->forgetCache();
    $player->silver = 9999;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.unavailable')));
});

it('lists damaged gear on repair screen', function (): void {
    $player = cityDone(9808, 'SmithRepairList');
    backpack()->addItem($player->tg_id, 'mobile_3');
    $gloves = backpack()->findOwned($player->tg_id, 'mobile_3');
    $gloves->durability = 10;
    $gloves->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair')));
    assertCityEditMarkupHas('city:blacksmith:repair:' . $gloves->id);
    assertCityEditMarkupHas('city:blacksmith:repair_all');
    assertCityEditHas(' · ');
});

it('shows repair empty when nothing is damaged', function (): void {
    $player = cityDone(9809, 'SmithRepairEmpty');

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair_empty')));
    assertCityEditMarkupHas('city:blacksmith');
});

it('repairs one item through telegram', function (): void {
    $player = cityDone(9810, 'SmithRepairOk');
    backpack()->addItem($player->tg_id, 'mobile_1');
    $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
    $boots->durability = $boots->max_durability - 5;
    $boots->save();
    $cost = repair()->repairCost($boots);
    $player->silver = $cost;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:' . $boots->id);

    $boots->refresh();
    expect($boots->durability)->toBe($boots->max_durability);
    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repaired')));
});

it('shows not enough silver on single repair', function (): void {
    $player = cityDone(9811, 'SmithRepairPoor');
    backpack()->addItem($player->tg_id, 'mobile_1');
    $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
    $boots->durability = $boots->max_durability - 5;
    $boots->save();
    $player->silver = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:' . $boots->id);

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_silver')));
    assertCityEditMarkupHas('city:blacksmith:repair:' . $boots->id);
});

it('repairs all damaged gear for gold', function (): void {
    $player = cityDone(9812, 'SmithRepairAllOk');
    backpack()->addItem($player->tg_id, 'mobile_3');
    $gloves = backpack()->findOwned($player->tg_id, 'mobile_3');
    $gloves->durability = 10;
    $gloves->save();
    $gold = repair()->repairAllGoldCost($player);
    $player->gold = $gold;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all');

    $gloves->refresh();
    $player->refresh();
    expect($gloves->durability)->toBe($gloves->max_durability)
        ->and($player->gold)->toBe(0);
    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repaired_all')));
    assertCityEditMarkupHas('city:blacksmith');
});

it('shows not enough gold on repair all', function (): void {
    $player = cityDone(9813, 'SmithRepairAllPoor');
    backpack()->addItem($player->tg_id, 'mobile_3');
    $gloves = backpack()->findOwned($player->tg_id, 'mobile_3');
    $gloves->durability = 10;
    $gloves->save();
    $player->gold = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_gold')));
});

it('shows no blacksmith error with back to tavern when flag is off', function (): void {
    $player = cityDone(9814, 'SmithGone', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_blacksmith = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.no_blacksmith')));
    assertCityEditMarkupHas('city:tavern');
});
