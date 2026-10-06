<?php

declare(strict_types=1);

use App\Enums\Bag\BagKindEnum;
use App\Filament\Resources\Characters\Tables\BagTable;
use App\Models\BagItem;

it('builds bag table rows from loose bag items and catalog', function (): void {
    $character = characters()->createDraft(9120);
    $character = grantGemDurability($character, 'ruby_0', 10);

    $orphan = new BagItem;
    $orphan->tg_id = $character->tg_id;
    $orphan->kind = BagKindEnum::GEM;
    $orphan->catalog_id = 'missing_gem';
    $orphan->quantity = 1;
    $orphan->durability = 3;
    $orphan->backpack_item_id = null;
    $orphan->created_at = now();
    $orphan->save();

    $rows = BagTable::rowsFor($character->fresh());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['catalog_id'])->toBe('ruby_0')
        ->and($rows[0]['name'])->toBe('Рубин ученика')
        ->and($rows[0]['kind'])->toBe(BagKindEnum::GEM)
        ->and($rows[0]['in_catalog'])->toBeTrue()
        ->and($rows[0]['obtained_at'])->not->toBeNull()
        ->and($rows[1]['catalog_id'])->toBe('missing_gem')
        ->and($rows[1]['name'])->toBe('missing_gem')
        ->and($rows[1]['in_catalog'])->toBeFalse();
});

it('returns no bag table rows for an empty bag', function (): void {
    $character = characters()->createDraft(9121);

    expect(BagTable::rowsFor($character))->toBe([]);
});
