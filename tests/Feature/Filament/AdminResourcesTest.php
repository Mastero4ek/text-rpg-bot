<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Resources\Fights\Pages\ListFights;
use App\Filament\Resources\Fights\Pages\ViewFight;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Models\Character;
use App\Models\Fight;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Gem\GemService;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
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
        ->assertSeeLivewire(LoadoutRelationManager::class)
        ->assertSeeLivewire(BackpackRelationManager::class)
        ->assertSeeLivewire(BagRelationManager::class);

    livewire(BackpackRelationManager::class, [
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

it('updates backpack capacity from edit relation manager', function (): void {
    $character = characters()->createDraft(9105);
    $character->inventory_max_rows = 50;
    $character->save();

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->set('inventoryMaxRows', 7)
        ->assertSet('inventoryMaxRows', 7);

    expect($character->fresh()->inventory_max_rows)->toBe(7);
});

it('rejects invalid backpack capacity on edit relation manager', function (): void {
    $character = characters()->createDraft(9106);
    $character->inventory_max_rows = 50;
    $character->save();

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->set('inventoryMaxRows', 0)
        ->assertSet('inventoryMaxRows', 50)
        ->assertNotified(__('admin.actions.set_inventory_max_rows.invalid'));

    expect($character->fresh()->inventory_max_rows)->toBe(50);
});

it('discards backpack item from edit relation manager action', function (): void {
    $character = characters()->createDraft(9107);
    inventory()->addItem($character->tg_id, 'knife_0');
    $item = Inventory::query()
        ->where('tg_id', $character->tg_id)
        ->where('item_id', 'knife_0')
        ->firstOrFail();

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('discard')->table($item))
        ->assertNotified(__('admin.actions.discard.notification'))
        ->assertDispatched('character-gems-changed');

    expect(Inventory::query()->whereKey($item->id)->exists())->toBeFalse();
});

it('moves socketed gems to bag when discarding backpack item', function (): void {
    $character = characters()->createDraft(9143);
    $character->gem_pouch = gemPouch('ruby_0', 8);
    $character->save();

    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $socket = app(GemService::class)->socket($character, $knife->id, 0);
    expect($socket->ok)->toBeTrue();
    $character = $socket->character;
    $knife->refresh();

    expect(app(GemService::class)->pouch($character))->toBe([])
        ->and(app(GemService::class)->socketedGemIds($knife))->toBe(['ruby_0']);

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('discard')->table($knife))
        ->assertNotified(__('admin.actions.discard.notification'))
        ->assertDispatched('character-gems-changed');

    $character = $character->fresh();
    $pouch = app(GemService::class)->pouch($character);

    expect(Inventory::query()->whereKey($knife->id)->exists())->toBeFalse()
        ->and($pouch)->toHaveCount(1)
        ->and($pouch[0]['gem_id'])->toBe('ruby_0')
        ->and($pouch[0]['durability'])->toBe(8);

    $stale = Character::query()->findOrFail($character->tg_id);
    $stale->gem_pouch = [];

    livewire(BagRelationManager::class, [
        'ownerRecord' => $stale,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->assertDontSee('Рубин ученика')
        ->call('refreshAfterExternalChange')
        ->assertSee('Рубин ученика');
});

it('equips backpack item into loadout from edit relation manager', function (): void {
    $character = characters()->createDraft(9108);
    inventory()->addItem($character->tg_id, 'knife_0');
    $item = Inventory::query()
        ->where('tg_id', $character->tg_id)
        ->where('item_id', 'knife_0')
        ->firstOrFail();

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('equip')->table($item), data: [
            'slot' => SlotEnum::RIGHT_HAND->value,
        ])
        ->assertNotified(__('admin.actions.equip.notification'))
        ->assertDispatched('character-loadout-changed')
        ->assertDispatched('character-vitals-changed');

    expect($item->fresh()->isEquipped())->toBeTrue()
        ->and(inventory()->rowCount($character->tg_id))->toBe(0);
});

it('lists all loadout slots on character relation manager', function (): void {
    $character = characters()->createDraft(9135);
    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertSee($knife->item_name)
        ->assertSee(SlotEnum::RIGHT_HAND->getLabel())
        ->assertSee(SlotEnum::LEFT_HAND->getLabel())
        ->assertSee(SlotEnum::AMULET->getLabel());
});

it('unequips loadout item from edit relation manager', function (): void {
    $character = characters()->createDraft(9136);
    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    expect(inventory()->rowCount($character->tg_id))->toBe(0);

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('unequip')->table(SlotEnum::RIGHT_HAND->value))
        ->assertNotified(__('admin.actions.unequip.notification'))
        ->assertDispatched('character-vitals-changed')
        ->assertDispatched('character-loadout-changed');

    expect($knife->fresh()->isEquipped())->toBeFalse()
        ->and(inventory()->rowCount($character->tg_id))->toBe(1);
});

it('does not register unequip action on loadout view relation manager', function (): void {
    $character = characters()->createDraft(9137);
    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertActionDoesNotExist(TestAction::make('unequip')->table(SlotEnum::RIGHT_HAND->value));
});

it('rejects unequip from loadout when backpack is full', function (): void {
    $character = characters()->createDraft(9138);
    $character->inventory_max_rows = 1;
    $character->save();

    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;
    inventory()->addItem($character->tg_id, 'axe_0');

    expect(inventory()->isFull($character))->toBeTrue();

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character->fresh(),
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('unequip')->table(SlotEnum::RIGHT_HAND->value))
        ->assertNotified(__('errors.inventory_full'));

    expect($knife->fresh()->isEquipped())->toBeTrue()
        ->and(inventory()->rowCount($character->tg_id))->toBe(1);
});

it('shows unequip stat preview in loadout confirmation modal', function (): void {
    $character = characters()->createDraft(9139);
    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->mountAction(TestAction::make('unequip')->table(SlotEnum::RIGHT_HAND->value))
        ->assertMountedActionModalSee(__('admin.actions.equip.stat_damage'));
});

it('hides unequip action on empty loadout slots', function (): void {
    $character = characters()->createDraft(9140);

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->assertActionHidden(TestAction::make('unequip')->table(SlotEnum::RIGHT_HAND->value));
});

it('discards equipped item from loadout edit relation manager', function (): void {
    $character = characters()->createDraft(9141);
    $character->inventory_max_rows = 1;
    $character->save();

    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;
    inventory()->addItem($character->tg_id, 'axe_0');

    expect(inventory()->isFull($character))->toBeTrue();

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character->fresh(),
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('discard')->table(SlotEnum::RIGHT_HAND->value))
        ->assertNotified(__('admin.actions.discard.notification'))
        ->assertDispatched('character-loadout-changed')
        ->assertDispatched('character-gems-changed')
        ->assertDispatched('character-vitals-changed');

    expect(Inventory::query()->whereKey($knife->id)->exists())->toBeFalse()
        ->and(inventory()->rowCount($character->tg_id))->toBe(1);
});

it('does not register discard action on loadout view relation manager', function (): void {
    $character = characters()->createDraft(9142);
    inventory()->addItem($character->tg_id, 'knife_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $character = loadout()->equip($character, $knife->id)->character;

    livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertActionDoesNotExist(TestAction::make('discard')->table(SlotEnum::RIGHT_HAND->value));
});

it('hides equipped items from backpack relation manager', function (): void {
    $character = characters()->createDraft(9109);
    inventory()->addItem($character->tg_id, 'knife_0');
    inventory()->addItem($character->tg_id, 'axe_0');
    $knife = inventory()->findOwned($character->tg_id, 'knife_0');
    $axe = inventory()->findOwned($character->tg_id, 'axe_0');
    loadout()->equip($character, $knife->id);

    livewire(BackpackRelationManager::class, [
        'ownerRecord' => $character->fresh(),
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$axe])
        ->assertCanNotSeeTableRecords([$knife]);
});

it('updates bag capacity from edit relation manager', function (): void {
    $character = characters()->createDraft(9110);
    $character->bag_max_rows = 10;
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->set('bagMaxRows', 4)
        ->assertSet('bagMaxRows', 4);

    expect($character->fresh()->bag_max_rows)->toBe(4);
});

it('rejects invalid bag capacity on edit relation manager', function (): void {
    $character = characters()->createDraft(9111);
    $character->bag_max_rows = 10;
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->set('bagMaxRows', 0)
        ->assertSet('bagMaxRows', 10)
        ->assertNotified(__('admin.actions.set_bag_max_rows.invalid'));

    expect($character->fresh()->bag_max_rows)->toBe(10);
});

it('lists pouch gems on bag relation manager', function (): void {
    $character = characters()->createDraft(9112);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertSee('Рубин ученика');
});

it('discards pouch gem from edit bag relation manager', function (): void {
    $character = characters()->createDraft(9113);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('discard')->table('0'))
        ->assertNotified(__('admin.actions.discard_gem.notification'));

    expect(app(GemService::class)->pouch($character->fresh()))->toBe([]);
});

it('sockets pouch gem into equipment from edit bag relation manager', function (): void {
    $character = characters()->createDraft(9114);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    $character = giveAndEquipStarterKnuckles($character);
    $knuckles = inventory()->findOwned($character->tg_id, shopCatalog()->starterKnucklesId());

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->callAction(TestAction::make('socket')->table('0'), data: [
            'inventory_id' => $knuckles->id,
        ])
        ->assertNotified(__('admin.actions.socket_gem.notification'))
        ->assertDispatched('character-gems-changed');

    $character = $character->fresh();

    expect(app(GemService::class)->pouch($character))->toBe([])
        ->and(app(GemService::class)->socketedGemIds($knuckles->fresh()))->toBe(['ruby_0']);
});

it('refreshes loadout sockets after socketed gems change externally', function (): void {
    $character = characters()->createDraft(9144);
    $character = giveAndEquipStarterKnuckles($character);
    $knuckles = inventory()->findOwned($character->tg_id, shopCatalog()->starterKnucklesId());

    $loadout = livewire(LoadoutRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->assertDontSee('Рубин ученика');

    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();
    $socket = app(GemService::class)->socket($character, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();

    $loadout
        ->call('refreshAfterExternalChange')
        ->assertSee('Рубин ученика');
});

it('refreshes current hp on edit form when vitals change event fires', function (): void {
    $character = characters()->createDraft(9145);
    $character->current_hp = 50;
    $character->save();

    $page = livewire(EditCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'current_hp' => 50,
        ]);

    $character->current_hp = 37;
    $character->save();

    $page
        ->call('refreshVitalsFromInventory')
        ->assertSchemaStateSet([
            'current_hp' => 37,
        ]);
});

it('shows no free sockets message when socketing without targets', function (): void {
    $character = characters()->createDraft(9115);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => EditCharacter::class,
    ])
        ->assertOk()
        ->mountAction(TestAction::make('socket')->table('0'))
        ->assertMountedActionModalSee(__('admin.actions.socket_gem.no_targets'));

    expect(app(GemService::class)->pouch($character->fresh()))->toHaveCount(1);
});

it('hides bag mutation actions on view relation manager', function (): void {
    $character = characters()->createDraft(9116);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    livewire(BagRelationManager::class, [
        'ownerRecord' => $character,
        'pageClass' => ViewCharacter::class,
    ])
        ->assertOk()
        ->assertActionDoesNotExist(TestAction::make('discard')->table('0'))
        ->assertActionDoesNotExist(TestAction::make('socket')->table('0'))
        ->assertActionVisible(TestAction::make('view')->table('0'));
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
