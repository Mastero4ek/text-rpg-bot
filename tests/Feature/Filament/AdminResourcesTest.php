<?php

declare(strict_types=1);

use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\InventoriesRelationManager;
use App\Filament\Resources\Fights\Pages\ListFights;
use App\Filament\Resources\Fights\Pages\ViewFight;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Models\Fight;
use App\Models\Inventory;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Bus;

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

it('shows inventory on character view and opens item view', function (): void {
    $character = characters()->createDraft(9102);
    inventory()->addItem($character->tg_id, 'knife_0');
    $item = Inventory::query()
        ->where('tg_id', $character->tg_id)
        ->where('item_id', 'knife_0')
        ->firstOrFail();

    livewire(ViewCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->assertOk()
        ->assertSeeLivewire(InventoriesRelationManager::class);

    livewire(InventoriesRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);

    livewire(ViewInventory::class, [
        'record' => $item->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'tg_id' => 9102,
            'item_id' => 'knife_0',
        ]);
});

it('lists and views live fights', function (): void {
    Bus::fake();

    $character = characters()->createDraft(9103);
    $character->username = 'FightAdmin';
    $character->save();

    $fight = fights()->createTraining($character, combat()->makeWoodenSoldier());

    livewire(ListFights::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$fight]);

    livewire(ViewFight::class, [
        'record' => $fight->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'tg_id' => 9103,
            'kind' => $fight->kind,
            'step' => $fight->step,
            'tutorial' => false,
        ]);
});

it('clears a live fight from admin view', function (): void {
    Bus::fake();

    $character = characters()->createDraft(9104);
    $character->username = 'FightClear';
    $character->save();

    $fight = fights()->createTraining($character, combat()->makeWoodenSoldier());

    livewire(ViewFight::class, [
        'record' => $fight->getKey(),
    ])
        ->assertOk()
        ->callAction(DeleteAction::class);

    expect(Fight::query()->whereKey($character->tg_id)->exists())->toBeFalse();
});

it('rejects guests from admin resources', function (): void {
    auth()->logout();

    $this->get(ListCharacters::getUrl())->assertRedirect();
    $this->get(ListFights::getUrl())->assertRedirect();
});
