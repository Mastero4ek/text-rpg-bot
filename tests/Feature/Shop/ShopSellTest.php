<?php

declare(strict_types=1);

use App\Actions\Inventory\InventorySellAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
use App\Models\Inventory;

it('sells unequipped gear for half price scaled by durability', function (): void {
    $p = characters()->createDraft(6101);
    $p->silver = 0;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 20;
    $knife->max_durability = 40;
    $knife->save();

    expect(inventory()->sellPayout($knife))->toBe(5);

    $sold = shopService()->sell($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(5)
        ->and(inventory()->owns($p->tg_id, 'knife_0'))->toBeFalse();
});

it('sells jewelry without durability at full sell ratio', function (): void {
    $p = characters()->createDraft(6102);
    $p->silver = 0;
    $p->save();

    inventory()->addItem($p->tg_id, 'focus_0');
    $ring = inventory()->findOwned($p->tg_id, 'focus_0');

    expect(inventory()->sellPayout($ring))->toBe(10);

    $sold = app(InventorySellAction::class)->handle($p, $ring->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(10)
        ->and(inventory()->owns($p->tg_id, 'focus_0'))->toBeFalse();
});

it('credits gold when selling a gold-priced item', function (): void {
    $equipment = Equipment::query()->findOrFail('sword_0');
    $equipment->currency = CurrencyEnum::GOLD;
    $equipment->price = 4;
    $equipment->save();
    shopCatalog()->forgetCache();

    $p = characters()->createDraft(6103);
    $p->gold = 0;
    $p->silver = 0;
    $p->save();

    inventory()->addItem($p->tg_id, 'sword_0');
    $sword = inventory()->findOwned($p->tg_id, 'sword_0');
    $sword->durability = $sword->max_durability;
    $sword->save();

    expect(inventory()->sellPayout($sword))->toBe(2);

    $sold = shopService()->sell($p, $sword->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->gold)->toBe(2)
        ->and($sold->character->silver)->toBe(0);
});

it('pays zero for broken gear and still removes the row', function (): void {
    $p = characters()->createDraft(6104);
    $p->silver = 7;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 0;
    $knife->save();

    expect(inventory()->sellPayout($knife))->toBe(0);

    $sold = shopService()->sell($p, $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(Inventory::query()->whereKey($knife->id)->exists())->toBeFalse();
});

it('rejects selling equipped items and unknown rows', function (): void {
    $p = characters()->createDraft(6105);
    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
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

    $row = new Inventory;
    $row->tg_id = $p->tg_id;
    $row->item_id = 'missing_catalog_item';
    $row->item_name = 'Ghost';
    $row->item_type = TypeEnum::WEAPON;
    $row->quantity = 1;
    $row->save();

    $sold = shopService()->sell($p, $row->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.cannot_sell'))
        ->and(Inventory::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('lists only unequipped rows as sellable and decrements potion stacks', function (): void {
    $p = characters()->createDraft(6107);
    $p->silver = 0;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    inventory()->addItem($p->tg_id, 'axe_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    inventory()->addItem($p->tg_id, shopCatalog()->shopPotionId());
    inventory()->addItem($p->tg_id, shopCatalog()->shopPotionId());
    $potion = inventory()->findOwned($p->tg_id, shopCatalog()->shopPotionId());

    expect(inventory()->sellableList($p->tg_id))->toHaveCount(2)
        ->and($potion->quantity)->toBe(2)
        ->and(inventory()->sellPayout($potion))->toBe(7);

    $sold = shopService()->sell($p, $potion->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(7)
        ->and(inventory()->findOwned($p->tg_id, shopCatalog()->shopPotionId())->quantity)->toBe(1);
});
