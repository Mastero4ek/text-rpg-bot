<?php

declare(strict_types=1);

use App\Enums\Enemy\EnemyKindEnum;
use App\Enums\Equipment\SlotEnum;
use App\Models\Enemy\EnemyCatalog;

it('builds wooden_soldier from catalog stats', function (): void {
    $p = characters()->createDraft(7101);
    $e = woodenSoldier($p);
    $row = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);

    expect($e->catalogId)->toBe(EnemyCatalog::TUTORIAL_CATALOG_ID)
        ->and($e->name)->toBe('Деревянный солдат')
        ->and($e->level)->toBe(0)
        ->and($e->strength)->toBe(2)
        ->and($e->agility)->toBe(2)
        ->and($e->instinct)->toBe(2)
        ->and($e->vitality)->toBe(3)
        ->and($e->maxHp)->toBe(60)
        ->and($e->currentHp)->toBe(60)
        ->and($e->weaponDamage)->toBe(0)
        ->and($e->attackSlots)->toBe(1)
        ->and($e->blockSlots)->toBe(1)
        ->and($e->armorByZone['HEAD'])->toBe(0)
        ->and($e->maxStamina)->toBe(combat()->maxStamina($row->strength))
        ->and($e->stamina)->toBe($e->maxStamina);
});

it('mirrors player loadout at 100 percent with full hp', function (): void {
    $p = characters()->createDraft(7102);
    $p->current_hp = 1;
    $p->save();
    $p = equipItemToSlot($p, 'mobile_0', SlotEnum::HELMET);
    $loadout = loadout()->forCharacter($p);
    $catalog = EnemyCatalog::query()->findOrFail('chance_wanderer');
    $e = enemies()->makeFromCatalog($catalog, $p);

    expect($e->level)->toBe($p->level)
        ->and($e->strength)->toBe($p->strength)
        ->and($e->agility)->toBe($p->agility)
        ->and($e->instinct)->toBe($p->instinct)
        ->and($e->vitality)->toBe($p->vitality)
        ->and($e->maxHp)->toBe(characters()->maxHp($p))
        ->and($e->currentHp)->toBe($e->maxHp)
        ->and($e->currentHp)->toBeGreaterThan(1)
        ->and($e->armorByZone)->toBe($loadout->armorByZone)
        ->and($e->weaponMf->toArray())->toBe($loadout->mfForMainHandAttack()->toArray())
        ->and($e->attackSlots)->toBe($loadout->attackSlots)
        ->and($e->blockSlots)->toBe($loadout->blockSlots)
        ->and($e->maxStamina)->toBe(combat()->maxStamina($p->strength));
});

it('scales mirror combat numbers by power_pct then stamina from strength', function (): void {
    $p = characters()->createDraft(7103);
    $p->strength = 10;
    $p->agility = 8;
    $p->instinct = 6;
    $p->vitality = 4;
    $p->save();
    $p = equipItemToSlot($p, 'mobile_0', SlotEnum::HELMET);

    $catalog = EnemyCatalog::factory()->mirror()->create([
        'power_pct' => 130,
    ]);
    $base = EnemyCatalog::factory()->mirror()->make([
        'power_pct' => 100,
    ]);
    $at100 = enemies()->makeFromCatalog($base, $p);
    $at130 = enemies()->makeFromCatalog($catalog, $p);

    expect($at130->strength)->toBe((int) floor($at100->strength * 130 / 100))
        ->and($at130->agility)->toBe((int) floor($at100->agility * 130 / 100))
        ->and($at130->maxHp)->toBe((int) floor($at100->maxHp * 130 / 100))
        ->and($at130->armorByZone['HEAD'])->toBe((int) floor($at100->armorByZone['HEAD'] * 130 / 100))
        ->and($at130->weaponMf->dodge)->toBe((int) floor($at100->weaponMf->dodge * 130 / 100))
        ->and($at130->maxStamina)->toBe(combat()->maxStamina($at130->strength))
        ->and($catalog->kind)->toBe(EnemyKindEnum::MIRROR);
});
