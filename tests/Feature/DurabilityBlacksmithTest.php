<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Models\Equipment;
use App\Services\Inventory\LoadoutService;

it('copies max durability onto inventory rows', function (): void {
    $p = characters()->createDraft(8201);
    $knuckles = giveStarterKnuckles($p->tg_id);

    expect($knuckles->max_durability)->toBe(40)
        ->and($knuckles->durability)->toBe(40);
});

it('stops giving bonuses at zero durability and restores after repair', function (): void {
    $p = characters()->createDraft(8202);
    inventory()->addItem($p->tg_id, 'mobile_0');
    $cap = inventory()->findOwned($p->tg_id, 'mobile_0');
    $eq = inventory()->equip($p, $cap->id);
    expect($eq->ok)->toBeTrue();
    $p = $eq->character;

    $before = app(LoadoutService::class)->forCharacter($p);
    expect($before->armorByZone['HEAD'])->toBeGreaterThan(0);

    $cap->durability = 0;
    $cap->save();

    $broken = app(LoadoutService::class)->forCharacter($p);
    expect($broken->armorByZone['HEAD'])->toBe(0)
        ->and($broken->row(SlotEnum::HELMET))->not->toBeNull();

    $p->silver = inventory()->repairCost($cap);
    $p->save();

    $repair = inventory()->repair($p, $cap->id);
    expect($repair->ok)->toBeTrue();
    $cap->refresh();
    expect($cap->durability)->toBe($cap->max_durability);

    $fixed = app(LoadoutService::class)->forCharacter($repair->character);
    expect($fixed->armorByZone['HEAD'])->toBe($before->armorByZone['HEAD']);
});

it('applies fight wear and can break equipped gear', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8203));
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 1;
    $knuckles->save();

    $broken = inventory()->applyFightWearAfterWin($p, 0);

    $knuckles->refresh();
    expect($knuckles->durability)->toBe(0)
        ->and($broken)->toContain($knuckles->item_name);

    $loadout = app(LoadoutService::class)->forCharacter(characters()->findByTgId($p->tg_id));
    expect($loadout->weaponDamageMax)->toBe(0);
});

it('charges repair by missing points and req_level', function (): void {
    $p = characters()->createDraft(8204);
    inventory()->addItem($p->tg_id, 'mobile_1');
    $boots = inventory()->findOwned($p->tg_id, 'mobile_1');
    $boots->durability = $boots->max_durability - 5;
    $boots->save();

    $equipment = Equipment::query()->findOrFail('mobile_1');
    $perPoint = gameConfig()->settings()['repair']['silverPerMissingPoint'];
    $reqLevel = $equipment->req_level ?? 0;
    $expected = 5 * $perPoint * ($reqLevel + 1);

    expect(inventory()->repairCost($boots))->toBe($expected);

    $p->silver = $expected - 1;
    $p->save();
    expect(inventory()->repair($p, $boots->id)->ok)->toBeFalse();

    $p->silver = $expected;
    $p->save();
    $ok = inventory()->repair($p, $boots->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->silver)->toBe(0);
});

it('broken shield loses second block slot', function (): void {
    $p = characters()->createDraft(8205);
    inventory()->addItem($p->tg_id, 'heavy_1');
    $shield = inventory()->findOwned($p->tg_id, 'heavy_1');
    $eq = inventory()->equip($p, $shield->id);
    expect($eq->ok)->toBeTrue();

    expect(app(LoadoutService::class)->forCharacter($eq->character)->blockSlots)->toBe(2);

    $shield->durability = 0;
    $shield->save();

    expect(app(LoadoutService::class)->forCharacter($eq->character)->blockSlots)->toBe(1);
});

it('lists only damaged repairable items for the smith', function (): void {
    $p = characters()->createDraft(8206);
    inventory()->addItem($p->tg_id, 'mobile_3');
    $gloves = inventory()->findOwned($p->tg_id, 'mobile_3');
    $gloves->durability = 10;
    $gloves->save();

    $list = inventory()->damagedList($p->tg_id);
    expect($list)->toHaveCount(1)
        ->and($list->first()->item_id)->toBe('mobile_3');
});
