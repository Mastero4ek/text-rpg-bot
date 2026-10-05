<?php

declare(strict_types=1);

use App\Enums\Gem\GemTypeEnum;
use App\Filament\Resources\Characters\Tables\GemPouchTable;

it('builds bag table rows from pouch and gem catalog', function (): void {
    $character = characters()->createDraft(9120);
    $character->gem_pouch = [
        ['gem_id' => 'ruby_0', 'durability' => 10, 'added_at' => '2026-10-05T12:00:00+00:00'],
        ['gem_id' => 'missing_gem', 'durability' => 3],
    ];
    $character->save();

    $rows = GemPouchTable::rowsFor($character);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['index'])->toBe(0)
        ->and($rows[0]['gem_id'])->toBe('ruby_0')
        ->and($rows[0]['name'])->toBe('Рубин ученика')
        ->and($rows[0]['type'])->toBe(GemTypeEnum::RUBY)
        ->and($rows[0]['in_catalog'])->toBeTrue()
        ->and($rows[0]['obtained_at'])->not->toBeNull()
        ->and($rows[1]['index'])->toBe(1)
        ->and($rows[1]['name'])->toBe('missing_gem')
        ->and($rows[1]['type'])->toBeNull()
        ->and($rows[1]['in_catalog'])->toBeFalse()
        ->and($rows[1]['obtained_at'])->toBeNull();
});

it('returns no bag table rows for an empty pouch', function (): void {
    $character = characters()->createDraft(9121);

    expect(GemPouchTable::rowsFor($character))->toBe([]);
});
