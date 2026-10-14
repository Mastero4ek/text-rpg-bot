<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Support\Gem\GemMfText;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

describe('offer', function (): void {
    it('opens buyer offer with chest and sell buttons', function (): void {
        $player = cityDone(9901, 'BuyerOffer');

        cityFlowCallback($player->tg_id, 9, 'city:buyer');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.offer')));
        assertCityEditHas('buyer_tavern.png');
        assertCityEditMarkupHas('city:buyer:chest');
        assertCityEditMarkupHas('city:buyer:sell');
        assertCityEditMarkupHas('city:tavern');
    });

    it('shows no buyer error with back to tavern when flag is off', function (): void {
        $player = cityDone(9908, 'BuyerGone', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_buyer = false;
        $city->save();

        cityFlowCallback($player->tg_id, 9, 'city:buyer');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
        assertCityEditMarkupHas('city:tavern');
    });
});

describe('chest', function (): void {
    it('opens chest with name-only gem rows and view callbacks', function (): void {
        $player = cityDone(9914, 'BuyerChest');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.chest')));
        assertCityEditMarkupHas('city:buyer:chest:view:ruby_0:all');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:buy:'));
        assertCityEditMarkupHas('city:buyer');
    });

    it('opens gem card with bonus durability price and without type label', function (): void {
        $player = cityDone(9915, 'BuyerChestCard');
        $gem = bagCatalog()->findGem('ruby_0');
        $typeLabel = $gem->type->getLabel();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:view:ruby_0:all');

        assertCityEditHas($gem->name);
        assertCityEditHas(GemMfText::forDef($gem));
        assertCityEditHas('🔧 ' . $gem->maxDurability . '/' . $gem->maxDurability);
        assertCityEditHas('🪙 -' . $gem->price);
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, $typeLabel . ' ·'));
        assertCityEditMarkupHas('city:buyer:buy:ruby_0');
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('buys gem from card and returns to chest list', function (): void {
        $player = cityDone(9903, 'BuyerBuyOk');
        $price = bagCatalog()->findGem('ruby_0')->price;
        $player->silver = $price;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:view:ruby_0:all');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:buy:ruby_0');

        expect(bag()->looseGems($player->fresh()))->toHaveCount(1)
            ->and(characters()->findByTgId($player->tg_id)->silver)->toBe(0);
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.bought', [
            'name' => 'Рубин ученика',
        ])));
        assertCityEditMarkupHas('city:buyer:chest:view:ruby_0:all');
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
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

    it('shows not enough silver narrative on gem buy and returns to chest', function (): void {
        $player = cityDone(9904, 'BuyerBuyPoor');
        $player->silver = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:ruby_0');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.not_enough_silver')));
        assertCityEditMarkupHas('city:buyer:chest:view:ruby_0:all');
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:buy:ruby_0'));
        expect(bag()->looseGems($player->fresh()))->toHaveCount(0);
    });

    it('shows not enough gold narrative on gold gem buy and returns to chest', function (): void {
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
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:buy:' . $gem->catalog_id));
        expect($player->fresh()->gold)->toBe(0);
    });

    it('shows bag full narrative on gem buy and stays on card', function (): void {
        $player = cityDone(9906, 'BuyerBuyFull');
        $player->bag_max_rows = 1;
        $player->silver = 999;
        $player->save();
        $player = grantGem($player, 'ruby_0', 1);

        cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:emerald_0');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.bag_full')));
        assertCityEditMarkupHas('city:buyer:buy:emerald_0');
        assertCityEditMarkupHas('"style":"success"');
        expect(hasLooseGem($player->fresh(), 'emerald_0'))->toBeFalse();
    });

    it('shows unavailable narrative when gem is not in city pivot on buy', function (): void {
        $player = cityDone(9907, 'BuyerBuyMiss', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->detach('ruby_0');
        bagCatalog()->forgetCache();
        $player->silver = 999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:buy:ruby_0');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.unavailable')));
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        expect(hasLooseGem($player->fresh(), 'ruby_0'))->toBeFalse();
    });

    it('shows unavailable on chest view when gem is not in city pivot', function (): void {
        $player = cityDone(9920, 'BuyerViewMiss', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->bagCatalog()->detach('ruby_0');
        bagCatalog()->forgetCache();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest:view:ruby_0:all');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.unavailable')));
        assertCityEditMarkupHas('city:buyer:chest:list:all:1');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:buy:ruby_0'));
    });

    it('shows no buyer on chest and buy when flag is off', function (): void {
        $player = cityDone(9921, 'BuyerChestGone', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_buyer = false;
        $city->save();
        $player->silver = 999;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:chest');
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
        assertCityEditMarkupHas('city:tavern');

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:buy:ruby_0');
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
        assertCityEditMarkupHas('city:tavern');
        expect(bag()->looseGems($player->fresh()))->toHaveCount(0);
    });
});

describe('sell', function (): void {
    it('lists backpack and bag sell rows with typed view callbacks', function (): void {
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
        assertCityEditMarkupHas('city:buyer:sell:view:bp:' . $knife->id . ':all');
        assertCityEditMarkupHas('city:buyer:sell:view:bag:' . $potion->id . ':all');
        assertCityEditMarkupHas('city:buyer:sell:view:bag:' . $gem->id . ':all');
        assertCityEditMarkupHas('×3');
        assertCityEditMarkupHas('city:buyer');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:sell_yes:'));
    });

    it('shows sell empty copy when there is nothing to sell', function (): void {
        $player = cityDone(9922, 'BuyerSellEmpty');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell');

        assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell_empty')));
        assertCityEditMarkupHas('city:buyer');
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, 'city:buyer:sell:view:'));
    });

    it('opens backpack sell card with payout and confirm buttons', function (): void {
        $player = cityDone(9913, 'BuyerSellConfirm');
        backpack()->addItem($player->tg_id, 'knife_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');
        $payout = backpack()->sellPayout($knife);

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bp:' . $knife->id . ':all');

        assertCityEditHas($knife->item_name);
        assertCityEditHas('🪙 +' . $payout);
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
        assertCityEditMarkupHas('city:buyer:sell_yes:bp:' . $knife->id);
        assertCityEditMarkupHas('city:buyer:sell:list:all:1');
    });

    it('opens gem sell card with bonus durability and payout without type label', function (): void {
        $player = cityDone(9923, 'BuyerSellGemCard');
        $player = grantGemDurability($player, 'ruby_0', 7);
        $gemRow = looseGem($player, 'ruby_0');
        $gem = bagCatalog()->findGem('ruby_0');
        $payout = bag()->sellPayout($gemRow);
        $typeLabel = $gem->type->getLabel();

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bag:' . $gemRow->id . ':all');

        assertCityEditHas($gem->name);
        assertCityEditHas(GemMfText::forDef($gem));
        assertCityEditHas('🔧 7/' . $gem->maxDurability);
        assertCityEditHas('🪙 +' . $payout);
        assertTelegramMarkupBody(fn (string $body): bool => ! str_contains($body, $typeLabel . ' ·'));
        assertCityEditMarkupHas('city:buyer:sell_yes:bag:' . $gemRow->id);
        assertCityEditMarkupHas('city:buyer:sell:list:all:1');
    });

    it('opens potion sell card with effect quantity and payout', function (): void {
        $player = cityDone(9924, 'BuyerSellPotionCard');
        bag()->addPotion($player->tg_id, 'heal_0');
        bag()->addPotion($player->tg_id, 'heal_0');
        $potionRow = bag()->loosePotions($player)->first();
        $potion = bagCatalog()->findPotion('heal_0');
        $payout = bag()->sellPayout($potionRow);

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bag:' . $potionRow->id . ':all');

        assertCityEditHas($potion->name);
        assertCityEditHas('❤️ +' . $potion->effectValue);
        assertCityEditHas('×2');
        assertCityEditHas('🪙 +' . $payout);
        assertCityEditMarkupHas('city:buyer:sell_yes:bag:' . $potionRow->id);
    });

    it('shows zero payout on sell card when gem durability is zero', function (): void {
        $player = cityDone(9925, 'BuyerSellZero');
        $player = grantGemDurability($player, 'ruby_0', 0);
        $gemRow = looseGem($player, 'ruby_0');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bag:' . $gemRow->id . ':all');

        assertCityEditHas('🪙 +0');
        assertCityEditMarkupHas('city:buyer:sell_yes:bag:' . $gemRow->id);
    });

    it('returns to sell list without selling when no is pressed', function (): void {
        $player = cityDone(9926, 'BuyerSellNo');
        backpack()->addItem($player->tg_id, 'knife_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bp:' . $knife->id . ':all');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:sell:list:all:1');

        expect(backpack()->owns($player->tg_id, 'knife_0'))->toBeTrue();
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell')));
        assertCityEditMarkupHas('city:buyer:sell:view:bp:' . $knife->id . ':all');
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
        assertCityEditMarkupHas('city:buyer:sell:view:bag:' . $potion->id . ':all');
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

    it('sells one potion unit through buyer sell_yes callback', function (): void {
        $player = cityDone(9927, 'BuyerSellPotionYes');
        $player->silver = 0;
        $player->save();
        bag()->addPotion($player->tg_id, 'heal_0');
        bag()->addPotion($player->tg_id, 'heal_0');
        $potion = bag()->loosePotions($player)->first();
        $payout = bag()->sellPayout($potion);

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell_yes:bag:' . $potion->id);

        expect(bag()->loosePotions($player->fresh())->first()->quantity)->toBe(1)
            ->and(characters()->findByTgId($player->tg_id)->silver)->toBe($payout);
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.sold', [
            'name' => bagCatalog()->findPotion('heal_0')->name,
            'price' => $payout,
            'mark' => '🪙',
        ])));
        assertCityEditMarkupHas('city:buyer:sell:view:bag:' . $potion->id . ':all');
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

    it('rejects sell view and sell_yes for missing backpack row', function (): void {
        $player = cityDone(9928, 'BuyerSellMissBp');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell:view:bp:999999:all');
        assertCityEditHas(mb_trim(__('errors.item_not_found')));

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:sell_yes:bp:999999');
        assertCityEditHas(mb_trim(__('errors.item_not_found')));
    });

    it('hides equipped backpack items from sell list and rejects forge view', function (): void {
        $player = cityDone(9929, 'BuyerSellEquipped');
        backpack()->addItem($player->tg_id, 'knife_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');
        $player = loadout()->equip($player, $knife->id)->character;

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell');
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.sell_empty')));
        assertTelegramMarkupBody(function (string $body) use ($knife): bool {
            return ! str_contains($body, 'city:buyer:sell:view:bp:' . $knife->id . ':');
        });

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:sell:view:bp:' . $knife->id . ':all');
        assertCityEditHas(mb_trim(__('errors.item_not_found')));
        expect(backpack()->owns($player->tg_id, 'knife_0'))->toBeTrue();
    });

    it('shows no buyer on sell screens when flag is off', function (): void {
        $player = cityDone(9930, 'BuyerSellGone', City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_buyer = false;
        $city->save();
        backpack()->addItem($player->tg_id, 'knife_0');
        $knife = backpack()->findOwned($player->tg_id, 'knife_0');

        cityFlowCallback($player->tg_id, 9, 'city:buyer:sell');
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
        assertCityEditMarkupHas('city:tavern');

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:buyer:sell:view:bp:' . $knife->id . ':all');
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));

        telegramHttpFake();
        cityFlowCallback($player->tg_id, 11, 'city:buyer:sell_yes:bp:' . $knife->id);
        assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
        expect(backpack()->owns($player->tg_id, 'knife_0'))->toBeTrue();
    });
});
