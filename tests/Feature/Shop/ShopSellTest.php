<?php

declare(strict_types=1);

use App\Actions\City\CityBuyerSellAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;
use App\Models\City;

it('sells unequipped gear for half price scaled by durability', function (): void {
    $p = placeInCity(characters()->createDraft(6101), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 20;
    $knife->max_durability = 40;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(5);

    $sold = buyerService()->sellBackpackItem($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(5)
        ->and(backpack()->owns($p->tg_id, 'knife_0'))->toBeFalse();
});

it('sells jewelry without durability at full sell ratio', function (): void {
    $p = placeInCity(characters()->createDraft(6102), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'focus_0');
    $ring = backpack()->findOwned($p->tg_id, 'focus_0');

    expect(backpack()->sellPayout($ring))->toBe(10);

    $sold = app(CityBuyerSellAction::class)->handleBackpack($p, $ring->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(10)
        ->and(backpack()->owns($p->tg_id, 'focus_0'))->toBeFalse();
});

it('credits gold when selling a gold-priced item', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('sword_0');
    $equipment->currency = CurrencyEnum::GOLD;
    $equipment->price = 4;
    $equipment->save();
    shopCatalog()->forgetCache();

    $p = placeInCity(characters()->createDraft(6103), City::KEY_ANKRAT);
    $p->gold = 0;
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'sword_0');
    $sword = backpack()->findOwned($p->tg_id, 'sword_0');
    $sword->durability = $sword->max_durability;
    $sword->save();

    expect(backpack()->sellPayout($sword))->toBe(2);

    $sold = buyerService()->sellBackpackItem($p, $sword->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->gold)->toBe(2)
        ->and($sold->character->silver)->toBe(0);
});

it('pays zero for broken gear and still removes the row', function (): void {
    $p = placeInCity(characters()->createDraft(6104), City::KEY_ANKRAT);
    $p->silver = 7;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 0;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(0);

    $sold = buyerService()->sellBackpackItem($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(BackpackItem::query()->whereKey($knife->id)->exists())->toBeFalse();
});

it('rejects selling equipped items and unknown rows', function (): void {
    $p = placeInCity(characters()->createDraft(6105), City::KEY_ANKRAT);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    $equipped = buyerService()->sellBackpackItem($p, $knife->id);

    expect($equipped->ok)->toBeFalse()
        ->and($equipped->error)->toBe(__('errors.unequip_first'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();

    $missing = buyerService()->sellBackpackItem($p, 9_999_999);

    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('errors.item_not_found'));
});

it('rejects selling without a buyer in the city', function (): void {
    $p = placeInCity(characters()->createDraft(6108), City::KEY_ELDWOOD);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');

    $sold = buyerService()->sellBackpackItem($p, $knife->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.no_buyer'))
        ->and(backpack()->owns($p->tg_id, 'knife_0'))->toBeTrue();
});

it('rejects selling rows with unknown catalog item ids', function (): void {
    $p = placeInCity(characters()->createDraft(6106), City::KEY_ANKRAT);

    $row = new BackpackItem;
    $row->tg_id = $p->tg_id;
    $row->catalog_id = 'missing_catalog_item';
    $row->item_name = 'Ghost';
    $row->item_type = TypeEnum::WEAPON;
    $row->save();

    $sold = buyerService()->sellBackpackItem($p, $row->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.cannot_sell'))
        ->and(BackpackItem::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('lists only unequipped backpack rows as sellable', function (): void {
    $p = characters()->createDraft(6107);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    backpack()->addItem($p->tg_id, 'axe_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    bag()->addPotion($p->tg_id, bagCatalog()->shopPotionId());

    expect(backpack()->sellableList($p->tg_id))->toHaveCount(1)
        ->and(backpack()->sellableList($p->tg_id)->first()->catalog_id)->toBe('axe_0');
});

it('sells a loose gem for half price scaled by durability', function (): void {
    $p = placeInCity(characters()->createDraft(6110), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();
    $p = grantGemDurability($p, 'ruby_0', 5);
    $gem = looseGem($p, 'ruby_0');

    expect(bag()->sellPayout($gem))->toBe(6);

    $sold = buyerService()->sellBagItem($p, $gem->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(6)
        ->and(hasLooseGem($sold->character, 'ruby_0'))->toBeFalse();
});

it('sells one potion from a stack and keeps the rest', function (): void {
    $p = placeInCity(characters()->createDraft(6111), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    bag()->addPotion($p->tg_id, 'heal_0');
    bag()->addPotion($p->tg_id, 'heal_0');
    $potion = bag()->loosePotions($p)->first();

    expect($potion->quantity)->toBe(2)
        ->and(bag()->sellPayout($potion))->toBe(7);

    $sold = app(CityBuyerSellAction::class)->handleBag($p, $potion->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(bag()->loosePotions($sold->character))->toHaveCount(1)
        ->and(bag()->loosePotions($sold->character)->first()->quantity)->toBe(1);
});

it('sells the last potion row completely', function (): void {
    $p = placeInCity(characters()->createDraft(6112), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    bag()->addPotion($p->tg_id, 'heal_0');
    $potion = bag()->loosePotions($p)->first();

    $sold = buyerService()->sellBagItem($p, $potion->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(bag()->loosePotions($sold->character))->toHaveCount(0);
});

it('rejects selling a socketed gem', function (): void {
    $p = placeInCity(characters()->createDraft(6113), City::KEY_ANKRAT);
    $p = grantGem($p, 'ruby_0', 1);
    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'ruby_0');

    expect($socket->ok)->toBeTrue();

    $knuckles->refresh();
    $gem = bag()->socketedInstances($knuckles)->first();

    $sold = buyerService()->sellBagItem($p, $gem->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.gem_socketed'))
        ->and(bag()->socketedInstances($knuckles))->toHaveCount(1);
});

it('rejects selling bag items without a buyer in the city', function (): void {
    $p = placeInCity(characters()->createDraft(6114), City::KEY_ELDWOOD);
    $p->silver = 0;
    $p->save();
    bag()->addPotion($p->tg_id, 'heal_0');
    $potion = bag()->loosePotions($p)->first();

    $sold = buyerService()->sellBagItem($p, $potion->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.no_buyer'))
        ->and(bag()->loosePotions($p))->toHaveCount(1);
});

it('rejects selling a missing bag row', function (): void {
    $p = placeInCity(characters()->createDraft(6115), City::KEY_ANKRAT);

    $sold = buyerService()->sellBagItem($p, 9_999_999);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.item_not_found'));
});

it('lists only loose bag rows as sellable', function (): void {
    $p = placeInCity(characters()->createDraft(6116), City::KEY_ANKRAT);
    $p = grantGem($p, 'ruby_0', 1);
    $p = grantGem($p, 'emerald_0', 1);
    bag()->addPotion($p->tg_id, 'heal_0');
    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    socketGem($p, $knuckles, 'ruby_0');

    $list = bag()->sellableLooseList($p->tg_id);

    expect($list)->toHaveCount(2)
        ->and($list->pluck('catalog_id')->all())->toEqualCanonicalizing(['emerald_0', 'heal_0']);
});
