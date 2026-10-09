<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('opens buyer offer with chest and sell buttons', function (): void {
    $player = cityDone(9901, 'BuyerOffer');

    cityFlowCallback($player->tg_id, 9, 'city:buyer');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.offer')));
    assertCityEditHas('buyer_tavern.png');
    assertCityEditMarkupHas('city:buyer:chest');
    assertCityEditMarkupHas('city:buyer:sell');
    assertCityEditMarkupHas('city:tavern');
});

it('opens chest with gem rows', function (): void {
    $player = cityDone(9914, 'BuyerChest');

    cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.chest')));
    assertCityEditMarkupHas('city:buyer:buy:ruby_0');
    assertCityEditHas('🪙 · ');
    assertCityEditMarkupHas('city:buyer');
});

it('shows empty chest when city has no non-potion bag pivot', function (): void {
    $player = cityDone(9902, 'BuyerEmpty', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->bagCatalog()->sync([]);
    bagCatalog()->forgetCache();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.empty')));
    assertCityEditMarkupHas('city:buyer');
});

it('buys gem through telegram and keeps chest markup', function (): void {
    $player = cityDone(9903, 'BuyerBuyOk');
    $price = bagCatalog()->findGem('ruby_0')->price;
    $player->silver = $price;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:ruby_0');

    expect(bag()->looseGems($player->fresh()))->toHaveCount(1);
    assertCityEditHas(mb_trim(__('telegram.npc.buyer.bought', [
        'name' => 'Рубин ученика',
    ])));
    assertCityEditMarkupHas('city:buyer:buy:ruby_0');
});

it('shows not enough silver narrative on gem buy', function (): void {
    $player = cityDone(9904, 'BuyerBuyPoor');
    $player->silver = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:ruby_0');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.not_enough_silver')));
    assertCityEditMarkupHas('city:buyer:buy:ruby_0');
});

it('shows not enough gold narrative on gold gem buy', function (): void {
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $gem = BagCatalog::factory()->create([
        'name' => 'Золотой камень теста',
        'price' => 3,
        'currency' => CurrencyEnum::GOLD,
        'enabled' => true,
    ]);
    $gem->cities()->sync([$city->id]);
    bagCatalog()->forgetCache();

    $player = cityDone(9905, 'BuyerBuyGoldPoor');
    $player->gold = 0;
    $player->silver = 999;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:' . $gem->catalog_id);

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.not_enough_gold')));
});

it('shows bag full narrative on gem buy', function (): void {
    $player = cityDone(9906, 'BuyerBuyFull');
    $player->bag_max_rows = 1;
    $player->silver = 999;
    $player->save();
    $player = grantGem($player, 'ruby_0', 1);

    cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:emerald_0');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.bag_full')));
});

it('shows unavailable narrative when gem is not in city pivot', function (): void {
    $player = cityDone(9907, 'BuyerBuyMiss', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->bagCatalog()->detach('ruby_0');
    bagCatalog()->forgetCache();
    $player->silver = 999;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:ruby_0');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.unavailable')));
});

it('shows no buyer error with back to tavern when flag is off', function (): void {
    $player = cityDone(9908, 'BuyerGone', City::KEY_ELDWOOD);

    cityFlowCallback($player->tg_id, 9, 'city:buyer');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
    assertCityEditMarkupHas('city:tavern');
});

it('lists backpack and bag sell rows with typed callbacks', function (): void {
    $player = cityDone(9909, 'BuyerSellList');
    backpack()->addItem($player->tg_id, 'knife_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    $player = grantGem($player, 'ruby_0', 1);

    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $potion = bag()->loosePotions($player)->first();
    $gem = looseGem($player, 'ruby_0');

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell')));
    assertCityEditMarkupHas('city:buyer:sell:bp:' . $knife->id);
    assertCityEditMarkupHas('city:buyer:sell:bag:' . $potion->id);
    assertCityEditMarkupHas('city:buyer:sell:bag:' . $gem->id);
    assertCityEditMarkupHas('×3');
    assertCityEditMarkupHas('city:buyer');
});

it('asks sell confirmation with red no and green yes', function (): void {
    $player = cityDone(9913, 'BuyerSellConfirm');
    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:bp:' . $knife->id);

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell_confirm', [
        'name' => $knife->item_name,
        'price' => backpack()->sellPayout($knife),
        'mark' => '🪙',
    ])));
    assertCityEditMarkupHas('"style":"danger"');
    assertCityEditMarkupHas('"style":"success"');
    assertCityEditMarkupHas('city:buyer:sell_yes:bp:' . $knife->id);
    assertCityEditMarkupHas('"callback_data":"city:buyer:sell"');
});

it('sells a backpack item through buyer sell_yes callback', function (): void {
    $player = cityDone(9910, 'BuyerSellBp');
    $player->silver = 0;
    $player->save();
    backpack()->addItem($player->tg_id, 'knife_0');
    bag()->addPotion($player->tg_id, 'heal_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $knife->durability = $knife->max_durability;
    $knife->save();
    $potion = bag()->loosePotions($player)->first();
    $payout = backpack()->sellPayout($knife);

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell_yes:bp:' . $knife->id);

    expect(backpack()->owns($player->tg_id, 'knife_0'))->toBeFalse()
        ->and(characters()->findByTgId($player->tg_id)->silver)->toBe($payout);
    assertCityEditHas(mb_trim(__('telegram.npc.buyer.sold', [
        'name' => $knife->item_name,
        'price' => $payout,
        'mark' => '🪙',
    ])));
    assertCityEditMarkupHas('city:buyer:sell:bag:' . $potion->id);
    assertCityEditMarkupHas('"callback_data":"city:buyer"');
});

it('sells a loose gem through buyer sell_yes callback', function (): void {
    $player = cityDone(9911, 'BuyerSellBag');
    $player->silver = 0;
    $player->save();
    $player = grantGemDurability($player, 'ruby_0', 10);
    $gem = looseGem($player, 'ruby_0');
    $payout = bag()->sellPayout($gem);

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell_yes:bag:' . $gem->id);

    expect(hasLooseGem($player->fresh(), 'ruby_0'))->toBeFalse()
        ->and(characters()->findByTgId($player->tg_id)->silver)->toBe($payout);
    assertCityEditHas(mb_trim(__('telegram.npc.buyer.sold', [
        'name' => 'Рубин ученика',
        'price' => $payout,
        'mark' => '🪙',
    ])));
    assertCityEditMarkupHas('"callback_data":"city:buyer"');
});
it('rejects socketed gem sell through forged buyer callback', function (): void {
    $player = cityDone(9912, 'BuyerSellSocketed');
    $player = grantGem($player, 'ruby_0', 1);
    $player = giveAndEquipStarterKnuckles($player);
    $knuckles = backpack()->findOwned($player->tg_id, shopCatalog()->starterKnucklesId());
    socketGem($player, $knuckles, 'ruby_0');
    $knuckles->refresh();
    $gem = bag()->socketedInstances($knuckles)->first();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell_yes:bag:' . $gem->id);

    expect(bag()->socketedInstances($knuckles))->toHaveCount(1);
    assertCityEditHas(mb_trim(__('errors.item_not_found')));
});
