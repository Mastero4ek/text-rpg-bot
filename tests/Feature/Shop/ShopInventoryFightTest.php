<?php

declare(strict_types=1);

it('buyWeapon and buyPotion happy and fail paths', function (): void {
    $p = characters()->createDraft(5001);
    $p->silver = 100;
    $p->save();

    $buy = shopService()->buyWeapon($p->tg_id, 'knife_0');
    expect($buy->ok)->toBeTrue()
        ->and(inventory()->owns($p->tg_id, 'knife_0'))->toBeTrue();

    expect(shopService()->buyWeapon($p->tg_id, 'knife_0')->ok)->toBeFalse();

    $p = characters()->findByTgId($p->tg_id);
    $p->silver = 0;
    $p->save();
    expect(shopService()->buyPotion($p->tg_id)->ok)->toBeFalse();

    $p->silver = shopCatalog()->potionPrice();
    $p->save();
    $potion = shopService()->buyPotion($p->tg_id);
    expect($potion->ok)->toBeTrue()
        ->and(inventory()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(1)
        ->and(inventory()->owns($p->tg_id, shopCatalog()->shopPotionId()))->toBeTrue();

    $p->silver = shopCatalog()->staminaPotionPrice();
    $p->save();
    $stamina = shopService()->buyStaminaPotion($p->tg_id);
    expect($stamina->ok)->toBeTrue()
        ->and(inventory()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::STAMINA))->toBe(1)
        ->and(inventory()->owns($p->tg_id, shopCatalog()->shopStaminaPotionId()))->toBeTrue();
});

it('equip weapon and armor', function (): void {
    $p = characters()->createDraft(5002);
    inventory()->addItem($p->tg_id, 'axe_0');
    inventory()->addItem($p->tg_id, shopCatalog()->mailShirtId());

    $weapon = inventory()->findOwned($p->tg_id, 'axe_0');
    $eq = inventory()->equip($p, $weapon->id);
    expect($eq->ok)->toBeTrue();
    $weapon->refresh();
    expect($weapon->is_equipped)->toBeTrue()
        ->and($weapon->slot)->toBe(App\Enums\Equipment\SlotEnum::RIGHT_HAND);

    $armor = inventory()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    $eqArmor = inventory()->equip($eq->character, $armor->id);
    expect($eqArmor->ok)->toBeTrue();
    $armor->refresh();
    expect($armor->is_equipped)->toBeTrue()
        ->and($armor->slot)->toBe(App\Enums\Equipment\SlotEnum::ARMOR);

    $uneq = inventory()->unequip($eqArmor->character, $armor->id);
    expect($uneq->ok)->toBeTrue();
    $armor->refresh();
    expect($armor->is_equipped)->toBeFalse();
});

it('fight create and clear', function (): void {
    $p = characters()->createDraft(5003);
    $enemy = combat()->makeWoodenSoldier();
    $fight = fights()->createTutorial($p, $enemy);

    expect($fight->tutorial)->toBeTrue()
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    app(App\Actions\Fight\FightClearAction::class)->handle($p->tg_id);
    expect(fights()->exists($p->tg_id))->toBeFalse();
});
