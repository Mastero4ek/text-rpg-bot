<?php

declare(strict_types=1);

it('buyWeapon and buyPotion happy and fail paths', function (): void {
    $p = characters()->createDraft(5001);
    $p->gold = 100;
    $p->save();

    $buy = shopService()->buyWeapon($p->tg_id, 'train_knife');
    expect($buy->ok)->toBeTrue()
        ->and(inventory()->owns($p->tg_id, 'train_knife'))->toBeTrue();

    expect(shopService()->buyWeapon($p->tg_id, 'train_knife')->ok)->toBeFalse();

    $p = characters()->findByTgId($p->tg_id);
    $p->gold = 0;
    $p->save();
    expect(shopService()->buyPotion($p->tg_id)->ok)->toBeFalse();

    $p->gold = shopCatalog()->potionPrice();
    $p->save();
    $potion = shopService()->buyPotion($p->tg_id);
    expect($potion->ok)->toBeTrue()
        ->and($potion->character->potions)->toBe(1);
});

it('equip weapon and armor', function (): void {
    $p = characters()->createDraft(5002);
    inventory()->addItem($p->tg_id, 'train_axe');
    inventory()->addItem($p->tg_id, shopCatalog()->mailShirtId());

    $weapon = inventory()->findOwned($p->tg_id, 'train_axe');
    $eq = inventory()->equip($p, $weapon->id);
    expect($eq->ok)->toBeTrue()
        ->and($eq->character->weapon_id)->toBe('train_axe');

    $armor = inventory()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    $eqArmor = inventory()->equip($eq->character, $armor->id);
    expect($eqArmor->ok)->toBeTrue()
        ->and($eqArmor->character->armor_id)->toBe(shopCatalog()->mailShirtId());
});

it('fight create and clear', function (): void {
    $p = characters()->createDraft(5003);
    $enemy = combat()->makeWoodenSoldier();
    $fight = fights()->createTutorial($p, $enemy);

    expect($fight->tutorial)->toBeTrue()
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    app(App\Actions\Fight\Clear\FightClearAction::class)->handle($p->tg_id);
    expect(fights()->exists($p->tg_id))->toBeFalse();
});
