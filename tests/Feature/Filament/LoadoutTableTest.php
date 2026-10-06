<?php

declare(strict_types=1);

use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Filament\Resources\Characters\Tables\LoadoutTable;

it('builds loadout rows for every gameplay slot with empty placeholders', function (): void {
    $character = characters()->createDraft(9130);

    $rows = LoadoutTable::rowsFor($character);
    $slots = SlotEnum::gameplayEquipSlots();

    expect($rows)->toHaveCount(count($slots));

    foreach ($rows as $index => $row) {
        expect($row['slot'])->toBe($slots[$index]->value)
            ->and($row['slot_label'])->toBe($slots[$index]->getLabel())
            ->and($row['equipped'])->toBeFalse()
            ->and($row['inventory_id'])->toBeNull()
            ->and($row['item_name'])->toBeNull()
            ->and($row['item_type'])->toBeNull()
            ->and($row['profile'])->toBeNull()
            ->and($row['image_url'])->toBeNull()
            ->and($row['socket_slots'])->toBe([])
            ->and($row['durability'])->toBeNull();
    }
});

it('fills equipped slot fields from inventory and catalog', function (): void {
    $character = characters()->createDraft(9131);
    backpack()->addItem($character->tg_id, 'knife_0');
    $knife = backpack()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    $rows = LoadoutTable::keyedRowsFor($character);
    $rightHand = $rows[SlotEnum::RIGHT_HAND->value];

    expect($rightHand['equipped'])->toBeTrue()
        ->and($rightHand['inventory_id'])->toBe($knife->id)
        ->and($rightHand['item_name'])->toBe($knife->item_name)
        ->and($rightHand['item_type'])->toBe(TypeEnum::WEAPON)
        ->and($rightHand['profile'])->toBe(ProfileEnum::KNIFE)
        ->and($rightHand['durability'])->toBe($knife->durability . '/' . $knife->max_durability)
        ->and($rows[SlotEnum::LEFT_HAND->value]['equipped'])->toBeFalse();
});

it('keys loadout rows by slot value', function (): void {
    $character = characters()->createDraft(9132);

    $keyed = LoadoutTable::keyedRowsFor($character);

    expect(array_keys($keyed))->toBe([
        SlotEnum::RIGHT_HAND->value,
        SlotEnum::LEFT_HAND->value,
        SlotEnum::SHIELD->value,
        SlotEnum::HELMET->value,
        SlotEnum::ARMOR->value,
        SlotEnum::PANTS->value,
        SlotEnum::BOOTS->value,
        SlotEnum::GLOVES->value,
        SlotEnum::RING_1->value,
        SlotEnum::RING_2->value,
        SlotEnum::AMULET->value,
    ]);
});

it('previews unequip stat deltas for equipped weapon', function (): void {
    $character = characters()->createDraft(9133);
    backpack()->addItem($character->tg_id, 'knife_0');
    $knife = backpack()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    $lines = loadout()->unequipStatChanges($character, $knife);

    expect($lines)->not->toBeEmpty();

    $kinds = [];

    foreach ($lines as $line) {
        $kinds[] = $line['kind'];
    }

    expect($kinds)->toContain('plain')
        ->and(collect($lines)->contains(
            fn (array $line): bool => ($line['label'] ?? null) === __('admin.actions.equip.stat_damage'),
        ))->toBeTrue();

    foreach ($lines as $line) {
        if ($line['kind'] !== 'delta') {
            continue;
        }

        expect($line['delta'])->toBeLessThan(0);
    }
});

it('returns no unequip stat changes note when item is not equipped', function (): void {
    $character = characters()->createDraft(9134);
    backpack()->addItem($character->tg_id, 'knife_0');
    $knife = backpack()->findOwned($character->tg_id, 'knife_0');

    $lines = loadout()->unequipStatChanges($character, $knife);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['kind'])->toBe('note')
        ->and($lines[0]['text'])->toBe(__('admin.actions.unequip.no_stat_changes'));
});
