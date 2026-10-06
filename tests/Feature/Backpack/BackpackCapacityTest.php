<?php

declare(strict_types=1);

use App\Actions\Backpack\BackpackEquipToSlotAction;
use App\Actions\Character\CharacterSetBackpackMaxRowsAction;
use App\Enums\Equipment\SlotEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\LoadoutSlot;
use App\Quest\ShopQuest;

it('seeds backpack_max_rows from settings on createDraft', function (): void {
    $p = characters()->createDraft(6301);

    expect($p->backpack_max_rows)->toBe(50)
        ->and(backpack()->maxRows($p))->toBe(50)
        ->and(backpack()->defaultMaxRows())->toBe(50)
        ->and(bag()->potionMaxStack())->toBe(5);
});

it('blocks buy and add when backpack rows are full', function (): void {
    $p = characters()->createDraft(6302);
    $p->backpack_max_rows = 1;
    $p->silver = 999;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');

    expect(backpack()->isFull($p))->toBeTrue()
        ->and(backpack()->canAcceptItem($p))->toBeFalse()
        ->and(shopService()->buyWeapon($p->tg_id, 'axe_0')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($p->tg_id, 'axe_0')->error)->toBe(__('errors.inventory_full'));

    expect(fn (): BackpackItem => backpack()->addItem($p->tg_id, 'axe_0'))
        ->toThrow(RuntimeException::class, 'Backpack is full.');
});

it('does not count equipped loadout rows toward backpack capacity', function (): void {
    $p = characters()->createDraft(6303);
    $p->backpack_max_rows = 1;
    $p->silver = 999;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $equip = app(BackpackEquipToSlotAction::class)->handle($p, $knife->id, SlotEnum::RIGHT_HAND);

    expect($equip->ok)->toBeTrue()
        ->and(LoadoutSlot::query()->where('tg_id', $p->tg_id)->count())->toBe(1)
        ->and(backpack()->rowCount($p->tg_id))->toBe(0)
        ->and(backpack()->isFull($equip->character))->toBeFalse();

    $buy = shopService()->buyWeapon($equip->character->tg_id, 'axe_0');

    expect($buy->ok)->toBeTrue()
        ->and(backpack()->rowCount($p->tg_id))->toBe(1)
        ->and(backpack()->isFull($buy->character))->toBeTrue();
});

it('rejects unequip when backpack is already full', function (): void {
    $p = characters()->createDraft(6304);
    $p->backpack_max_rows = 1;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    backpack()->addItem($p->tg_id, 'axe_0');

    expect(backpack()->isFull($p))->toBeTrue();

    $unequip = loadout()->unequip($p, $knife->id);

    expect($unequip->ok)->toBeFalse()
        ->and($unequip->error)->toBe(__('errors.inventory_full'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();
});

it('allows potion stacking into an existing stack when bag has space via stack', function (): void {
    $p = characters()->createDraft(6305);
    $p->bag_max_rows = 1;
    $p->backpack_max_rows = 1;
    $p->silver = 999;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    expect(backpack()->isFull($p))->toBeTrue();

    bag()->addPotion($p->tg_id, bagCatalog()->shopPotionId());

    expect(bag()->isBagFull($p))->toBeTrue()
        ->and(bag()->canAcceptPotion($p, bagCatalog()->shopPotionId()))->toBeTrue();

    $buy = shopService()->buyPotion($p->tg_id);

    expect($buy->ok)->toBeTrue()
        ->and(bag()->loosePotions($buy->character)->first()->quantity)->toBe(2)
        ->and(bag()->bagRowCount($buy->character))->toBe(1);
});

it('updates backpack_max_rows through action and rejects values below one', function (): void {
    $p = characters()->createDraft(6306);

    $updated = app(CharacterSetBackpackMaxRowsAction::class)->handle($p, 3);

    expect($updated->backpack_max_rows)->toBe(3)
        ->and(backpack()->maxRows($updated))->toBe(3);

    expect(fn () => app(CharacterSetBackpackMaxRowsAction::class)->handle($updated, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('blocks trainer club claim when backpack is full', function (): void {
    $p = characters()->createDraft(6307);
    $p->backpack_max_rows = 1;
    $p->onboarding_step = OnboardingStepEnum::QUEST_SHOP;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $trainerId = shopCatalog()->freeTrainerItemId();

    $finish = app(ShopQuest::class)->finishWithTrainerClub($p, $trainerId);

    expect($finish->ok)->toBeFalse()
        ->and($finish->error)->toBe(__('errors.inventory_full'))
        ->and($p->fresh()->onboarding_step)->toBe(OnboardingStepEnum::QUEST_SHOP)
        ->and(backpack()->owns($p->tg_id, $trainerId))->toBeFalse();
});
