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

it('shows no blacksmith error with back to tavern when flag is off', function (): void {
    $player = cityDone(9814, 'SmithGone', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_blacksmith = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.no_blacksmith')));
    assertCityEditMarkupHas('city:tavern');
});

describe('buy', function (): void {
    it('lists gear on stand with weapon filter primary by default', function (): void {
        $player = cityDone(9802, 'SmithGearList');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear')));
        assertCityEditMarkupHas('city:blacksmith:gear:view:sword_0:WEAPON');
        assertCityEditMarkupHas('city:blacksmith:gear:list:WEAPON:1');
        assertCityEditMarkupHas('city:blacksmith:gear:list:ARMOR:1');
        assertCityEditMarkupHas('city:blacksmith:gear:list:JEWELRY:1');
        assertCityEditMarkupHas('"style":"primary"');
        assertCityEditMarkupHas('city:blacksmith');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:buy:sword_0')
            && ! str_contains($body, 'city:blacksmith:buy:worn_mail')
            && ! str_contains($body, '🪙 -')
            && ! str_contains($body, __('telegram.btn.filter_all')));
    });

    it('lists armor items when armor filter is active', function (): void {
        $player = cityDone(9820, 'SmithGearArmor');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:list:ARMOR:1');

        assertCityEditMarkupHas('city:blacksmith:gear:view:mobile_1:ARMOR');
        assertCityEditMarkupHas('"style":"primary"');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:gear:view:sword_0:')
            && ! str_contains($body, 'city:blacksmith:buy:'));
    });

    it('opens gear card with price and buy row', function (): void {
        $player = cityDone(9815, 'SmithGearCard');
        $def = shopCatalog()->findItem('sword_0');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:view:sword_0:WEAPON');

        assertCityEditHas($def->itemName);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear_card_price', [
            'mark' => '🪙',
            'price' => $def->price,
        ])));
        assertCityEditMarkupHas('city:blacksmith:gear:list:WEAPON:1');
        assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('returns to stand from gear card back callback', function (): void {
        $player = cityDone(9821, 'SmithGearBack');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:view:sword_0:WEAPON');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:blacksmith:gear:list:WEAPON:1');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.gear')));
        assertCityEditMarkupHas('city:blacksmith:gear:view:sword_0:WEAPON');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:buy:sword_0'));
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

    it('buys gear from card and deducts silver', function (): void {
        $player = cityDone(9804, 'SmithBuyOk');
        $price = shopCatalog()->findItem('sword_0')->price;
        $player->silver = $price;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:view:sword_0:WEAPON');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:blacksmith:buy:sword_0');

        $player->refresh();
        expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeTrue()
            ->and($player->silver)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.bought', [
            'name' => shopCatalog()->findItem('sword_0')->itemName,
        ])));
        assertCityEditMarkupHas('city:blacksmith:gear:view:sword_0:WEAPON');
    });

    it('shows not enough silver narrative on gear buy and returns to stand', function (): void {
        $player = cityDone(9805, 'SmithBuyPoor');
        $player->silver = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_silver')));
        assertCityEditMarkupHas('city:blacksmith:gear:view:sword_0:WEAPON');
        assertCityEditMarkupHas('city:blacksmith:gear:list:WEAPON:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:buy:sword_0'));
        expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeFalse();
    });

    it('shows inventory full narrative on gear buy and stays on card', function (): void {
        $player = cityDone(9806, 'SmithBuyFull');
        $player->backpack_max_rows = 1;
        $player->silver = 9999;
        $player->save();
        backpack()->addItem($player->tg_id, 'hammer_0');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.inventory_full')));
        assertCityEditMarkupHas('city:blacksmith:buy:sword_0');
        assertCityEditMarkupHas('"style":"success"');
        expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeFalse();
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
        assertCityEditMarkupHas('city:blacksmith:gear:list:WEAPON:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:buy:sword_0'));
        expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeFalse();
    });

    it('shows unavailable on gear view when item is not in city pivot', function (): void {
        $player = cityDone(9822, 'SmithViewMiss', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->backpackCatalog()->detach('sword_0');
        shopCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:gear:view:sword_0:WEAPON');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.unavailable')));
        assertCityEditMarkupHas('city:blacksmith:gear:list:WEAPON:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:buy:sword_0'));
    });

    it('shows no blacksmith on buy when flag is off', function (): void {
        $player = cityDone(9823, 'SmithBuyGone', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_blacksmith = false;
        $city->save();
        $player->silver = 9999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:buy:sword_0');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.no_blacksmith')));
        assertCityEditMarkupHas('city:tavern');
        expect(backpack()->owns($player->tg_id, 'sword_0'))->toBeFalse();
    });
});

describe('repair', function (): void {
    it('lists damaged gear by name only with back and repair all in one row', function (): void {
        $player = cityDone(9808, 'SmithRepairList');
        backpack()->addItem($player->tg_id, 'ward_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $gloves->durability = 10;
        $gloves->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair')));
        assertCityEditMarkupHas('city:blacksmith:repair:view:' . $gloves->id . ':');
        assertCityEditMarkupHas('city:blacksmith:repair_all');
        assertCityEditMarkupHas(__('telegram.npc.blacksmith.repair_all_btn'));
        assertTelegramMarkupBody(function (string $body) use ($gloves): bool {
            return str_contains($body, 'city:blacksmith:repair:view:' . $gloves->id . ':')
                && str_contains($body, 'city:blacksmith')
                && str_contains($body, 'city:blacksmith:repair_all')
                && ! str_contains($body, 'city:blacksmith:repair:do:')
                && ! str_contains($body, '🥇 -')
                && ! str_contains($body, '🪙 -');
        });
    });

    it('opens repair card with durability price and repair row', function (): void {
        $player = cityDone(9816, 'SmithRepairCard');
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $boots->durability = $boots->max_durability - 5;
        $boots->save();
        $cost = repair()->repairCost($boots);

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:view:' . $boots->id . ':WEAPON');

        assertCityEditHas($boots->item_name);
        assertCityEditHas('🔧 ' . $boots->durability . '/' . $boots->max_durability);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair_card_price', [
            'mark' => '🪙',
            'price' => $cost,
        ])));
        assertCityEditMarkupHas('city:blacksmith:repair:list:WEAPON:1');
        assertCityEditMarkupHas('city:blacksmith:repair:do:' . $boots->id);
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('shows repair empty when nothing is damaged', function (): void {
        $player = cityDone(9809, 'SmithRepairEmpty');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair_empty')));
        assertCityEditMarkupHas('city:blacksmith');
    });

    it('repairs one item from card and deducts silver', function (): void {
        $player = cityDone(9810, 'SmithRepairOk');
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $boots->durability = $boots->max_durability - 5;
        $boots->save();
        $cost = repair()->repairCost($boots);
        $player->silver = $cost;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:view:' . $boots->id . ':ARMOR');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:blacksmith:repair:do:' . $boots->id);

        $boots->refresh();
        $player->refresh();
        expect($boots->durability)->toBe($boots->max_durability)
            ->and($player->silver)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repaired')));
        assertCityEditMarkupHas('city:blacksmith');
    });

    it('keeps repair list after fixing one item when more remain', function (): void {
        $player = cityDone(9824, 'SmithRepairRemain');
        backpack()->addItem($player->tg_id, 'mobile_1');
        backpack()->addItem($player->tg_id, 'ward_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $boots->durability = 5;
        $boots->save();
        $gloves->durability = 5;
        $gloves->save();
        $cost = repair()->repairCost($boots);
        $player->silver = $cost;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:do:' . $boots->id);

        $boots->refresh();
        expect($boots->durability)->toBe($boots->max_durability);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repaired')));
        assertCityEditMarkupHas('city:blacksmith:repair:view:' . $gloves->id . ':');
        assertCityEditMarkupHas('city:blacksmith:repair_all');
    });

    it('shows not enough silver on single repair and returns to list', function (): void {
        $player = cityDone(9811, 'SmithRepairPoor');
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $boots->durability = $boots->max_durability - 5;
        $boots->save();
        $player->silver = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:do:' . $boots->id);

        $boots->refresh();
        expect($boots->durability)->toBe($boots->max_durability - 5);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_silver')));
        assertCityEditMarkupHas('city:blacksmith:repair:view:' . $boots->id . ':');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:blacksmith:repair:do:'));
    });

    it('shows already repaired when viewing an undamaged item', function (): void {
        $player = cityDone(9825, 'SmithRepairWhole');
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:view:' . $boots->id . ':ARMOR');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.already_repaired')));
        assertCityEditMarkupHas('city:blacksmith');
    });

    it('shows already repaired when repairing an undamaged item', function (): void {
        $player = cityDone(9826, 'SmithRepairDoWhole');
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $player->silver = 9999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair:do:' . $boots->id);

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.already_repaired')));
        assertCityEditMarkupHas('city:blacksmith');
    });

    it('shows repair all confirm with items and durability', function (): void {
        $player = cityDone(9817, 'SmithRepairAllAsk');
        backpack()->addItem($player->tg_id, 'ward_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $gloves->durability = 10;
        $gloves->save();
        $gold = repair()->repairAllGoldCost($player);

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair_all_confirm', [
            'items' => __('telegram.npc.blacksmith.repair_all_confirm_item', [
                'name' => $gloves->item_name,
                'current' => 10,
                'max' => $gloves->max_durability,
            ]),
            'price' => $gold,
        ])));
        assertCityEditMarkupHas('city:blacksmith:repair_all:yes');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
        expect(backpack()->findOwned($player->tg_id, 'ward_1')->durability)->toBe(10);
    });

    it('cancels repair all confirm and returns to list without repairing', function (): void {
        $player = cityDone(9827, 'SmithRepairAllNo');
        backpack()->addItem($player->tg_id, 'ward_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $gloves->durability = 10;
        $gloves->save();
        $player->gold = 999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:blacksmith:repair:list:ARMOR:1');

        $gloves->refresh();
        $player->refresh();
        expect($gloves->durability)->toBe(10)
            ->and($player->gold)->toBe(999);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repair')));
        assertCityEditMarkupHas('city:blacksmith:repair:view:' . $gloves->id . ':ARMOR');
    });

    it('repairs all damaged gear for gold after confirm', function (): void {
        $player = cityDone(9812, 'SmithRepairAllOk');
        backpack()->addItem($player->tg_id, 'ward_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $gloves->durability = 10;
        $gloves->save();
        $gold = repair()->repairAllGoldCost($player);
        $player->gold = $gold;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all:yes');

        $gloves->refresh();
        $player->refresh();
        expect($gloves->durability)->toBe($gloves->max_durability)
            ->and($player->gold)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.repaired_all')));
        assertCityEditMarkupHas('city:blacksmith');
    });

    it('shows not enough gold on repair all confirm yes and returns to list', function (): void {
        $player = cityDone(9813, 'SmithRepairAllPoor');
        backpack()->addItem($player->tg_id, 'ward_1');
        $gloves = backpack()->findOwned($player->tg_id, 'ward_1');
        $gloves->durability = 10;
        $gloves->save();
        $player->gold = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair_all:yes');

        $gloves->refresh();
        expect($gloves->durability)->toBe(10);
        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.not_enough_gold')));
        assertCityEditMarkupHas('city:blacksmith:repair:view:' . $gloves->id . ':');
    });

    it('shows no blacksmith on repair when flag is off', function (): void {
        $player = cityDone(9828, 'SmithRepairGone', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_blacksmith = false;
        $city->save();
        backpack()->addItem($player->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($player->tg_id, 'mobile_1');
        $boots->durability = 5;
        $boots->save();

        cityFlowCallback($player->tg_id, 9, 'city:blacksmith:repair');

        assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.error.no_blacksmith')));
        assertCityEditMarkupHas('city:tavern');
    });
});
