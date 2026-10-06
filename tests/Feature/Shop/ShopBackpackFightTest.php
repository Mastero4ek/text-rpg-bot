<?php

declare(strict_types=1);

it('buyWeapon and buyPotion happy and fail paths', function (): void {
    $p = characters()->createDraft(5001);
    $p->silver = 100;
    $p->save();

    $buy = shopService()->buyWeapon($p->tg_id, 'knife_0');
    expect($buy->ok)->toBeTrue()
        ->and(backpack()->owns($p->tg_id, 'knife_0'))->toBeTrue();

    expect(shopService()->buyWeapon($p->tg_id, 'knife_0')->ok)->toBeFalse();

    $p = characters()->findByTgId($p->tg_id);
    $p->silver = 0;
    $p->save();
    expect(shopService()->buyPotion($p->tg_id)->ok)->toBeFalse();

    $p->silver = bagCatalog()->potionPrice();
    $p->save();
    $potion = shopService()->buyPotion($p->tg_id);
    expect($potion->ok)->toBeTrue()
        ->and(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(1)
        ->and(bag()->loosePotions($potion->character)->first()->catalog_id)->toBe(bagCatalog()->shopPotionId());

    $p->silver = bagCatalog()->staminaPotionPrice();
    $p->save();
    $stamina = shopService()->buyStaminaPotion($p->tg_id);
    expect($stamina->ok)->toBeTrue()
        ->and(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::STAMINA))->toBe(1);
});

it('equip weapon and armor', function (): void {
    $p = characters()->createDraft(5002);
    backpack()->addItem($p->tg_id, 'axe_0');
    backpack()->addItem($p->tg_id, shopCatalog()->mailShirtId());

    $weapon = backpack()->findOwned($p->tg_id, 'axe_0');
    $eq = loadout()->equip($p, $weapon->id);
    expect($eq->ok)->toBeTrue();
    $weapon->refresh();
    expect($weapon->isEquipped())->toBeTrue()
        ->and($weapon->slot)->toBe(App\Enums\Equipment\SlotEnum::RIGHT_HAND);

    $armor = backpack()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    $eqArmor = loadout()->equip($eq->character, $armor->id);
    expect($eqArmor->ok)->toBeTrue();
    $armor->refresh();
    expect($armor->isEquipped())->toBeTrue()
        ->and($armor->slot)->toBe(App\Enums\Equipment\SlotEnum::ARMOR);

    $uneq = loadout()->unequip($eqArmor->character, $armor->id);
    expect($uneq->ok)->toBeTrue();
    $armor->refresh();
    expect($armor->isEquipped())->toBeFalse();
});

it('fight create and clear', function (): void {
    $p = characters()->createDraft(5003);
    $enemy = woodenSoldier($p);
    $fight = fights()->createTutorial($p, $enemy);

    expect($fight->tutorial)->toBeTrue()
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    app(App\Actions\Fight\FightClearAction::class)->handle($p->tg_id);
    expect(fights()->exists($p->tg_id))->toBeFalse();
});
