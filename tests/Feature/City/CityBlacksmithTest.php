<?php

declare(strict_types=1);

use App\Models\City;

describe('blacksmith stand buy', function (): void {
    it('buys catalog item when city has blacksmith and pivot', function (): void {
        $p = placeInCity(characters()->createDraft(9301), City::KEY_ANKRAT);
        $price = shopCatalog()->findItem('sword_0')->price;
        $p->silver = $price;
        $p->save();

        $res = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($res->ok)->toBeTrue()
            ->and(backpack()->owns($p->tg_id, 'sword_0'))->toBeTrue()
            ->and($res->character->silver)->toBe(0);
    });

    it('allows buying a second copy of the same catalog item', function (): void {
        $p = placeInCity(characters()->createDraft(9302), City::KEY_ANKRAT);
        $price = shopCatalog()->findItem('sword_0')->price;
        $p->silver = $price * 2;
        $p->save();

        expect(blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0')->ok)->toBeTrue();
        $second = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($second->ok)->toBeTrue()
            ->and(backpack()->rowCount($p->tg_id))->toBe(2);
    });

    it('rejects when silver is short', function (): void {
        $p = placeInCity(characters()->createDraft(9303), City::KEY_ANKRAT);
        $p->silver = 0;
        $p->save();

        $res = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.not_enough_silver'));
    });

    it('rejects when backpack is full', function (): void {
        $p = placeInCity(characters()->createDraft(9304), City::KEY_ANKRAT);
        $p->backpack_max_rows = 1;
        $p->silver = 9999;
        $p->save();
        backpack()->addItem($p->tg_id, 'hammer_0');

        $res = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.inventory_full'));
    });

    it('rejects when item is missing from city pivot', function (): void {
        $p = placeInCity(characters()->createDraft(9305), City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->backpackCatalog()->detach('sword_0');
        shopCatalog()->forgetCache();
        $p->silver = 9999;
        $p->save();

        $res = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.item_not_in_shop'));
    });

    it('rejects when city has no blacksmith', function (): void {
        $p = placeInCity(characters()->createDraft(9306), City::KEY_ELDWOOD);
        $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
        $city->has_blacksmith = false;
        $city->save();
        $p->silver = 9999;
        $p->save();

        $res = blacksmithService()->buyCatalogItem($p->tg_id, 'sword_0');

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.no_blacksmith'));
    });
});

describe('blacksmith repair', function (): void {
    it('repairs a damaged item for silver', function (): void {
        $p = placeInCity(characters()->createDraft(9310), City::KEY_ANKRAT);
        backpack()->addItem($p->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($p->tg_id, 'mobile_1');
        $boots->durability = $boots->max_durability - 5;
        $boots->save();
        $cost = repair()->repairCost($boots);
        $p->silver = $cost;
        $p->save();

        $res = repair()->repair($p, $boots->id);

        expect($res->ok)->toBeTrue();
        $boots->refresh();
        expect($boots->durability)->toBe($boots->max_durability)
            ->and($res->character->silver)->toBe(0);
    });

    it('rejects repair when silver is short', function (): void {
        $p = placeInCity(characters()->createDraft(9311), City::KEY_ANKRAT);
        backpack()->addItem($p->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($p->tg_id, 'mobile_1');
        $boots->durability = $boots->max_durability - 5;
        $boots->save();
        $p->silver = 0;
        $p->save();

        $res = repair()->repair($p, $boots->id);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.not_enough_silver'));
    });

    it('rejects repair when item is already full', function (): void {
        $p = placeInCity(characters()->createDraft(9312), City::KEY_ANKRAT);
        backpack()->addItem($p->tg_id, 'mobile_1');
        $boots = backpack()->findOwned($p->tg_id, 'mobile_1');
        $p->silver = 999;
        $p->save();

        $res = repair()->repair($p, $boots->id);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.already_repaired'));
    });

    it('repairs all damaged items for gold', function (): void {
        $p = placeInCity(characters()->createDraft(9313), City::KEY_ANKRAT);
        backpack()->addItem($p->tg_id, 'mobile_3');
        $gloves = backpack()->findOwned($p->tg_id, 'mobile_3');
        $gloves->durability = 10;
        $gloves->save();
        $gold = repair()->repairAllGoldCost($p);
        $p->gold = $gold;
        $p->save();

        $res = repair()->repairAll($p);

        expect($res->ok)->toBeTrue();
        $gloves->refresh();
        expect($gloves->durability)->toBe($gloves->max_durability)
            ->and($res->character->gold)->toBe(0);
    });

    it('rejects repair all when gold is short', function (): void {
        $p = placeInCity(characters()->createDraft(9314), City::KEY_ANKRAT);
        backpack()->addItem($p->tg_id, 'mobile_3');
        $gloves = backpack()->findOwned($p->tg_id, 'mobile_3');
        $gloves->durability = 10;
        $gloves->save();
        $p->gold = 0;
        $p->save();

        $res = repair()->repairAll($p);

        expect($res->ok)->toBeFalse()
            ->and($res->error)->toBe(__('errors.not_enough_gold'));
    });
});
