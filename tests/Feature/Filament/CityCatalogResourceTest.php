<?php

declare(strict_types=1);

use App\Filament\Resources\BackpackCatalog\Pages\CreateBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\EditBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ListBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ViewBackpackCatalog;
use App\Filament\Resources\CityCatalog\Pages\CreateCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\EditCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\ListCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\ViewCityCatalog;
use App\Models\Backpack\BackpackCatalog;
use App\Models\City;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists views and creates a city', function (): void {
    $yasen = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();

    livewire(ListCityCatalog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$yasen]);

    livewire(ViewCityCatalog::class, [
        'record' => $yasen->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'name' => 'Анкрат',
        ]);

    livewire(CreateCityCatalog::class)
        ->fillForm([
            'name' => 'Дубрава',
            'portal_cost_silver' => 5,
            'enabled' => true,
            'has_blacksmith' => true,
            'has_healer' => true,
            'has_buyer' => false,
            'has_quest_board' => true,
            'has_portal' => true,
            'has_fights_list' => true,
            'has_forest' => false,
            'has_training_room' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(City::query()->where('name', 'Дубрава')->firstOrFail()->key)->toBe('dubrava')
        ->and(City::query()->where('name', 'Дубрава')->firstOrFail()->has_blacksmith)->toBeTrue();
});

it('rejects duplicate city name', function (): void {
    livewire(CreateCityCatalog::class)
        ->fillForm([
            'name' => 'Анкрат',
            'portal_cost_silver' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

it('saves catalog city chips and clones pivot', function (): void {
    $yasen = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $liman = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $knife = BackpackCatalog::query()->findOrFail('knife_0');

    livewire(EditBackpackCatalog::class, [
        'record' => $knife->getKey(),
    ])
        ->fillForm([
            'cities' => [$yasen->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($knife->fresh()->cities()->pluck('cities.id')->all())->toBe([$yasen->id]);

    livewire(ViewBackpackCatalog::class, [
        'record' => $knife->getKey(),
    ])
        ->callAction('clone')
        ->assertRedirect(CreateBackpackCatalog::getUrl());

    livewire(CreateBackpackCatalog::class)
        ->assertSchemaStateSet([
            'cities' => [$yasen->id],
        ]);

    livewire(EditBackpackCatalog::class, [
        'record' => $knife->getKey(),
    ])
        ->fillForm([
            'cities' => [$yasen->id, $liman->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('lists catalog without city pivot', function (): void {
    $item = BackpackCatalog::factory()->create([
        'name' => 'Без городов',
    ]);

    livewire(ListBackpackCatalog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);
});

it('shows archive action and refuses while characters reference the city', function (): void {
    $city = City::factory()->create(['name' => 'Ссылка']);
    placeInCity(characters()->createDraft(9130), $city->key);

    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->assertActionVisible(DeleteAction::class)
        ->callAction(DeleteAction::class);

    expect($city->fresh()->trashed())->toBeFalse();
});

it('keeps pivot on soft archive and cascades on force delete', function (): void {
    $city = City::factory()->create(['name' => 'Архив']);
    $knife = BackpackCatalog::query()->findOrFail('knife_0');
    $knife->cities()->attach($city->id);

    $city->delete();

    expect(DB::table('city_backpack_catalog')->where('city_id', $city->id)->exists())->toBeTrue();

    $city->restore();
    $city->refresh();
    expect($knife->cities()->whereKey($city->id)->exists())->toBeTrue();

    $city->delete();
    livewire(EditCityCatalog::class, [
        'record' => $city->getKey(),
    ])
        ->callAction(ForceDeleteAction::class);

    expect(City::withTrashed()->whereKey($city->id)->exists())->toBeFalse()
        ->and(DB::table('city_backpack_catalog')->where('city_id', $city->id)->exists())->toBeFalse();
});
