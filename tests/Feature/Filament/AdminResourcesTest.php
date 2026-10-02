<?php

declare(strict_types=1);

use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Fights\Pages\ListFights;
use App\Filament\Resources\Fights\Pages\ViewFight;
use App\Filament\Resources\Inventories\Pages\ListInventories;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Models\Inventory;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists and views characters', function (): void {
    $character = characters()->createDraft(9101);
    $character->username = 'AdminHero';
    $character->save();

    livewire(ListCharacters::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$character]);

    livewire(ViewCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'tg_id' => 9101,
            'username' => 'AdminHero',
        ]);
});

it('lists and views inventory items', function (): void {
    $character = characters()->createDraft(9102);
    inventory()->addItem($character->tg_id, 'train_knife');
    $item = Inventory::query()->where('tg_id', $character->tg_id)->firstOrFail();

    livewire(ListInventories::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);

    livewire(ViewInventory::class, [
        'record' => $item->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'tg_id' => 9102,
            'item_id' => 'train_knife',
        ]);
});

it('lists and views fights', function (): void {
    $character = characters()->createDraft(9103);
    $character->username = 'Fighter';
    $character->save();
    $fight = fights()->createTutorial($character, combat()->makeWoodenSoldier());

    livewire(ListFights::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$fight]);

    livewire(ViewFight::class, [
        'record' => $fight->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'tg_id' => 9103,
            'tutorial' => true,
        ]);
});

it('rejects guests from admin resources', function (): void {
    auth()->logout();

    $this->get(ListCharacters::getUrl())->assertRedirect();
});
