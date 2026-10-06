<?php

declare(strict_types=1);

use App\Actions\Character\CharacterSetInventoryMaxRowsAction;
use App\Actions\Inventory\InventoryEquipToSlotAction;
use App\Enums\Equipment\SlotEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Inventory;
use App\Models\LoadoutSlot;
use App\Quest\ShopQuest;

it('seeds inventory_max_rows from settings on createDraft', function (): void {
    $p = characters()->createDraft(6301);

    expect($p->inventory_max_rows)->toBe(50)
        ->and(inventory()->maxRows($p))->toBe(50)
        ->and(inventory()->defaultMaxRows())->toBe(50)
        ->and(inventory()->potionMaxStack())->toBe(5);
});

it('blocks buy and add when backpack rows are full', function (): void {
    $p = characters()->createDraft(6302);
    $p->inventory_max_rows = 1;
    $p->silver = 999;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');

    expect(inventory()->isFull($p))->toBeTrue()
        ->and(inventory()->canAcceptItem($p, 'axe_0'))->toBeFalse()
        ->and(shopService()->buyWeapon($p->tg_id, 'axe_0')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($p->tg_id, 'axe_0')->error)->toBe(__('errors.inventory_full'));

    expect(fn (): Inventory => inventory()->addItem($p->tg_id, 'axe_0'))
        ->toThrow(RuntimeException::class, 'Backpack is full.');
});

it('does not count equipped loadout rows toward backpack capacity', function (): void {
    $p = characters()->createDraft(6303);
    $p->inventory_max_rows = 1;
    $p->silver = 999;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $equip = app(InventoryEquipToSlotAction::class)->handle($p, $knife->id, SlotEnum::RIGHT_HAND);

    expect($equip->ok)->toBeTrue()
        ->and(LoadoutSlot::query()->where('tg_id', $p->tg_id)->count())->toBe(1)
        ->and(inventory()->rowCount($p->tg_id))->toBe(0)
        ->and(inventory()->isFull($equip->character))->toBeFalse();

    $buy = shopService()->buyWeapon($equip->character->tg_id, 'axe_0');

    expect($buy->ok)->toBeTrue()
        ->and(inventory()->rowCount($p->tg_id))->toBe(1)
        ->and(inventory()->isFull($buy->character))->toBeTrue();
});

it('rejects unequip when backpack is already full', function (): void {
    $p = characters()->createDraft(6304);
    $p->inventory_max_rows = 1;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    inventory()->addItem($p->tg_id, 'axe_0');

    expect(inventory()->isFull($p))->toBeTrue();

    $unequip = loadout()->unequip($p, $knife->id);

    expect($unequip->ok)->toBeFalse()
        ->and($unequip->error)->toBe(__('errors.inventory_full'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();
});

it('allows potion stacking into an existing stack when backpack is full', function (): void {
    $p = characters()->createDraft(6305);
    $p->inventory_max_rows = 1;
    $p->silver = 999;
    $p->save();

    inventory()->addItem($p->tg_id, shopCatalog()->shopPotionId());

    expect(inventory()->isFull($p))->toBeTrue()
        ->and(inventory()->canAcceptItem($p, shopCatalog()->shopPotionId()))->toBeTrue();

    $buy = shopService()->buyPotion($p->tg_id);

    expect($buy->ok)->toBeTrue()
        ->and(inventory()->findOwned($p->tg_id, shopCatalog()->shopPotionId())->quantity)->toBe(2)
        ->and(inventory()->rowCount($p->tg_id))->toBe(1);
});

it('updates inventory_max_rows through action and rejects values below one', function (): void {
    $p = characters()->createDraft(6306);

    $updated = app(CharacterSetInventoryMaxRowsAction::class)->handle($p, 3);

    expect($updated->inventory_max_rows)->toBe(3)
        ->and(inventory()->maxRows($updated))->toBe(3);

    expect(fn () => app(CharacterSetInventoryMaxRowsAction::class)->handle($updated, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('blocks trainer club claim when backpack is full', function (): void {
    $p = characters()->createDraft(6307);
    $p->inventory_max_rows = 1;
    $p->onboarding_step = OnboardingStepEnum::QUEST_SHOP;
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $trainerId = shopCatalog()->freeTrainerItemId();

    $finish = app(ShopQuest::class)->finishWithTrainerClub($p, $trainerId);

    expect($finish->ok)->toBeFalse()
        ->and($finish->error)->toBe(__('errors.inventory_full'))
        ->and($p->fresh()->onboarding_step)->toBe(OnboardingStepEnum::QUEST_SHOP)
        ->and(inventory()->owns($p->tg_id, $trainerId))->toBeFalse();
});
