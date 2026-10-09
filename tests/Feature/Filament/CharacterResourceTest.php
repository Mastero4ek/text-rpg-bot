<?php

declare(strict_types=1);

use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Models\Character;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('can edit character and applies experience thresholds on exp growth', function (): void {
    $character = characters()->createDraft(1501);
    $character->username = 'EditHero';
    $character->stat_points = 0;
    $character->silver = 0;
    $character->exp = 0;
    $character->level = 0;
    $character->save();

    $city = registration()->cities()[0];

    livewire(EditCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->fillForm([
            'username' => 'EditHero',
            'birth_city_id' => $city->id,
            'city_id' => $city->id,
            'exp' => 200,
            'level' => 0,
            'stat_points' => 0,
            'strength' => $character->strength,
            'agility' => $character->agility,
            'instinct' => $character->instinct,
            'vitality' => $character->vitality,
            'current_hp' => $character->current_hp,
            'max_hp' => $character->max_hp,
            'current_stamina' => $character->current_stamina,
            'max_stamina' => $character->max_stamina,
            'silver' => 0,
            'gold' => 0,
            'arena_points' => 0,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $character->fresh();

    expect($fresh->exp)->toBe(200)
        ->and($fresh->level)->toBe(1)
        ->and($fresh->stat_points)->toBe(6)
        ->and($fresh->city_id)->toBe($city->id);
});

it('rejects forbidden nick on edit', function (): void {
    $character = characters()->createDraft(1502);
    $character->username = 'GoodNick';
    $character->save();

    livewire(EditCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->fillForm([
            'username' => 'blyat',
            'exp' => $character->exp,
            'level' => $character->level,
            'stat_points' => $character->stat_points,
            'strength' => $character->strength,
            'agility' => $character->agility,
            'instinct' => $character->instinct,
            'vitality' => $character->vitality,
            'current_hp' => $character->current_hp,
            'max_hp' => $character->max_hp,
            'current_stamina' => $character->current_stamina,
            'max_stamina' => $character->max_stamina,
            'silver' => $character->silver,
            'gold' => $character->gold,
            'arena_points' => $character->arena_points,
        ])
        ->call('save')
        ->assertHasFormErrors(['username']);
});

it('resets stats from edit form action', function (): void {
    $start = gameConfig()->character()['start'];
    $character = characters()->createDraft(1503);
    $character->username = 'ResetHero';
    $character->strength = 12;
    $character->agility = 11;
    $character->instinct = 10;
    $character->vitality = 9;
    $character->stat_points = 0;
    $character->save();

    livewire(EditCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->callAction(TestAction::make('resetStats')->schemaComponent('characterIdentityActions'));

    $fresh = $character->fresh();

    expect($fresh->strength)->toBe($start['strength'])
        ->and($fresh->agility)->toBe($start['agility'])
        ->and($fresh->instinct)->toBe($start['instinct'])
        ->and($fresh->vitality)->toBe($start['vitality'])
        ->and($fresh->stat_points)->toBe(characters()->totalEarnedStatPoints($fresh));
});

it('archives restores and force deletes from edit page', function (): void {
    Bus::fake();

    $character = characters()->createDraft(1504);
    $character->username = 'ArchiveHero';
    $character->save();
    backpack()->addItem($character->tg_id, 'knife_0');
    fights()->createTraining($character, woodenSoldier($character));

    livewire(EditCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(Character::withTrashed()->findOrFail(1504)->trashed())->toBeTrue();

    livewire(EditCharacter::class, [
        'record' => 1504,
    ])
        ->callAction(RestoreAction::class);

    expect(Character::query()->findOrFail(1504)->trashed())->toBeFalse();

    livewire(EditCharacter::class, [
        'record' => 1504,
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(Character::withTrashed()->whereKey(1504)->exists())->toBeFalse();
});

it('filters characters by level and searches tg id', function (): void {
    $low = characters()->createDraft(1505);
    $low->username = 'LevelLow';
    $low->level = 0;
    $low->save();

    $high = characters()->createDraft(1506);
    $high->username = 'LevelHigh';
    $high->level = 3;
    $high->save();

    livewire(ListCharacters::class)
        ->filterTable('level', 3)
        ->assertCanSeeTableRecords([$high])
        ->assertCanNotSeeTableRecords([$low]);

    livewire(ListCharacters::class)
        ->searchTable('1505')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$high]);
});

it('archives character from list table action', function (): void {
    $character = characters()->createDraft(1508);
    $character->username = 'ListArchive';
    $character->save();

    livewire(ListCharacters::class)
        ->callAction(TestAction::make('delete')->table($character));

    expect(Character::withTrashed()->findOrFail(1508)->trashed())->toBeTrue();
});

it('view page keeps form disabled and exposes only edit header action', function (): void {
    $character = characters()->createDraft(1507);
    $character->username = 'ViewHero';
    $character->save();

    livewire(ViewCharacter::class, [
        'record' => $character->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'username' => 'ViewHero',
        ])
        ->assertActionExists('edit');
});
