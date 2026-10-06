<?php

declare(strict_types=1);

use App\Actions\Backpack\BackpackSellAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;

it('sells unequipped gear for half price scaled by durability', function (): void {
    $p = characters()->createDraft(6101);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 20;
    $knife->max_durability = 40;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(5);

    $sold = shopService()->sell($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(5)
        ->and(backpack()->owns($p->tg_id, 'knife_0'))->toBeFalse();
});

it('sells jewelry without durability at full sell ratio', function (): void {
    $p = characters()->createDraft(6102);
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'focus_0');
    $ring = backpack()->findOwned($p->tg_id, 'focus_0');

    expect(backpack()->sellPayout($ring))->toBe(10);

    $sold = app(BackpackSellAction::class)->handle($p, $ring->id);

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

    $p = characters()->createDraft(6103);
    $p->gold = 0;
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'sword_0');
    $sword = backpack()->findOwned($p->tg_id, 'sword_0');
    $sword->durability = $sword->max_durability;
    $sword->save();

    expect(backpack()->sellPayout($sword))->toBe(2);

    $sold = shopService()->sell($p, $sword->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->gold)->toBe(2)
        ->and($sold->character->silver)->toBe(0);
});

it('pays zero for broken gear and still removes the row', function (): void {
    $p = characters()->createDraft(6104);
    $p->silver = 7;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 0;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(0);

    $sold = shopService()->sell($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(BackpackItem::query()->whereKey($knife->id)->exists())->toBeFalse();
});

it('rejects selling equipped items and unknown rows', function (): void {
    $p = characters()->createDraft(6105);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    $equipped = shopService()->sell($p, $knife->id);

    expect($equipped->ok)->toBeFalse()
        ->and($equipped->error)->toBe(__('errors.unequip_first'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();

    $missing = shopService()->sell($p, 9_999_999);

    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('errors.item_not_found'));
});

it('rejects selling rows with unknown catalog item ids', function (): void {
    $p = characters()->createDraft(6106);

    $row = new BackpackItem;
    $row->tg_id = $p->tg_id;
    $row->catalog_id = 'missing_catalog_item';
    $row->item_name = 'Ghost';
    $row->item_type = TypeEnum::WEAPON;
    $row->save();

    $sold = shopService()->sell($p, $row->id);

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
