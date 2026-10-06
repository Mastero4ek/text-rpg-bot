<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Models\BackpackCatalog;
use App\Services\Backpack\LoadoutService;

it('copies max durability onto inventory rows', function (): void {
    $p = characters()->createDraft(8201);
    $knuckles = giveStarterKnuckles($p->tg_id);

    expect($knuckles->max_durability)->toBe(40)
        ->and($knuckles->durability)->toBe(40);
});

it('stops giving bonuses at zero durability and restores after repair', function (): void {
    $p = characters()->createDraft(8202);
    backpack()->addItem($p->tg_id, 'mobile_0');
    $cap = backpack()->findOwned($p->tg_id, 'mobile_0');
    $eq = loadout()->equip($p, $cap->id);
    expect($eq->ok)->toBeTrue();
    $p = $eq->character;

    $before = app(LoadoutService::class)->forCharacter($p);
    expect($before->armorByZone['HEAD'])->toBeGreaterThan(0);

    $cap->durability = 0;
    $cap->save();

    $broken = app(LoadoutService::class)->forCharacter($p);
    expect($broken->armorByZone['HEAD'])->toBe(0)
        ->and($broken->row(SlotEnum::HELMET))->not->toBeNull();

    $p->silver = repair()->repairCost($cap);
    $p->save();

    $repair = repair()->repair($p, $cap->id);
    expect($repair->ok)->toBeTrue();
    $cap->refresh();
    expect($cap->durability)->toBe($cap->max_durability);

    $fixed = app(LoadoutService::class)->forCharacter($repair->character);
    expect($fixed->armorByZone['HEAD'])->toBe($before->armorByZone['HEAD']);
});

it('applies fight wear and can break equipped gear', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8203));
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 1;
    $knuckles->save();

    $broken = loadout()->applyFightWearAfterWin($p, 0);

    $knuckles->refresh();
    expect($knuckles->durability)->toBe(0)
        ->and($broken)->toContain($knuckles->item_name);

    $loadout = app(LoadoutService::class)->forCharacter(characters()->findByTgId($p->tg_id));
    expect($loadout->weaponDamageMax)->toBe(0);
});

it('charges repair by missing points and req_level', function (): void {
    $p = characters()->createDraft(8204);
    backpack()->addItem($p->tg_id, 'mobile_1');
    $boots = backpack()->findOwned($p->tg_id, 'mobile_1');
    $boots->durability = $boots->max_durability - 5;
    $boots->save();

    $equipment = BackpackCatalog::query()->findOrFail('mobile_1');
    $perPoint = gameConfig()->settings()['repair']['silverPerMissingPoint'];
    $reqLevel = $equipment->req_level ?? 0;
    $expected = 5 * $perPoint * ($reqLevel + 1);

    expect(repair()->repairCost($boots))->toBe($expected);

    $p->silver = $expected - 1;
    $p->save();
    expect(repair()->repair($p, $boots->id)->ok)->toBeFalse();

    $p->silver = $expected;
    $p->save();
    $ok = repair()->repair($p, $boots->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->silver)->toBe(0);
});

it('broken shield loses second block slot', function (): void {
    $p = characters()->createDraft(8205);
    backpack()->addItem($p->tg_id, 'heavy_1');
    $shield = backpack()->findOwned($p->tg_id, 'heavy_1');
    $eq = loadout()->equip($p, $shield->id);
    expect($eq->ok)->toBeTrue();

    expect(app(LoadoutService::class)->forCharacter($eq->character)->blockSlots)->toBe(2);

    $shield->durability = 0;
    $shield->save();

    expect(app(LoadoutService::class)->forCharacter($eq->character)->blockSlots)->toBe(1);
});

it('lists only damaged repairable items for the smith', function (): void {
    $p = characters()->createDraft(8206);
    backpack()->addItem($p->tg_id, 'mobile_3');
    $gloves = backpack()->findOwned($p->tg_id, 'mobile_3');
    $gloves->durability = 10;
    $gloves->save();

    $list = repair()->damagedList($p->tg_id);
    expect($list)->toHaveCount(1)
        ->and($list->first()->catalog_id)->toBe('mobile_3');
});
