<?php

declare(strict_types=1);

use App\Enums\Enemy\EnemyKindEnum;
use App\Filament\Resources\EnemyCatalog\Pages\CreateEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\EditEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\ListEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\ViewEnemyCatalog;
use App\Models\Enemy\EnemyCatalog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists and views enemy catalog', function (): void {
    $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);

    livewire(ListEnemyCatalog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$soldier]);

    livewire(ViewEnemyCatalog::class, [
        'record' => $soldier->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'catalog_id' => EnemyCatalog::TUTORIAL_CATALOG_ID,
            'name' => 'Деревянный солдат',
        ]);
});

it('creates fixed and mirror via filament form', function (): void {
    $fixedId = EnemyCatalog::nextCatalogIdForKind(EnemyKindEnum::FIXED);

    livewire(CreateEnemyCatalog::class)
        ->fillForm(enemyForm([
            'catalog_id' => $fixedId,
            'name' => 'Кастомный болван',
            'kind' => EnemyKindEnum::FIXED->value,
            'level' => 2,
            'strength' => 4,
            'max_hp' => 90,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EnemyCatalog::query()->findOrFail($fixedId)->strength)->toBe(4)
        ->and(EnemyCatalog::query()->findOrFail($fixedId)->power_pct)->toBeNull();

    $mirrorId = EnemyCatalog::nextCatalogIdForKind(EnemyKindEnum::MIRROR);

    livewire(CreateEnemyCatalog::class)
        ->fillForm(enemyForm([
            'catalog_id' => $mirrorId,
            'name' => 'Кастомная тень',
            'kind' => EnemyKindEnum::MIRROR->value,
            'power_pct' => 80,
            'level' => null,
            'strength' => null,
            'agility' => null,
            'instinct' => null,
            'vitality' => null,
            'max_hp' => null,
            'weapon_damage' => null,
            'mf_dodge' => null,
            'mf_anti_dodge' => null,
            'mf_crit' => null,
            'mf_anti_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EnemyCatalog::query()->findOrFail($mirrorId)->kind)->toBe(EnemyKindEnum::MIRROR)
        ->and(EnemyCatalog::query()->findOrFail($mirrorId)->power_pct)->toBe(80)
        ->and(EnemyCatalog::query()->findOrFail($mirrorId)->strength)->toBeNull();
});

it('edits enemy name', function (): void {
    $mob = EnemyCatalog::query()->findOrFail('chance_wanderer');

    livewire(EditEnemyCatalog::class, [
        'record' => $mob->getKey(),
    ])
        ->fillForm([
            'name' => 'Бродяга улиц',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(EnemyCatalog::query()->findOrFail('chance_wanderer')->name)->toBe('Бродяга улиц');
});

it('archives restores and force deletes a regular mob', function (): void {
    $mob = EnemyCatalog::factory()->create([
        'catalog_id' => 'temp_mob',
        'name' => 'Временный моб',
    ]);

    livewire(EditEnemyCatalog::class, [
        'record' => $mob->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(EnemyCatalog::withTrashed()->findOrFail('temp_mob')->trashed())->toBeTrue();

    livewire(EditEnemyCatalog::class, [
        'record' => 'temp_mob',
    ])
        ->callAction(RestoreAction::class);

    expect(EnemyCatalog::query()->findOrFail('temp_mob')->trashed())->toBeFalse();

    livewire(EditEnemyCatalog::class, [
        'record' => 'temp_mob',
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(EnemyCatalog::withTrashed()->where('catalog_id', 'temp_mob')->exists())->toBeFalse();
});

it('hides force delete for tutorial soldier', function (): void {
    $soldier = EnemyCatalog::query()->findOrFail(EnemyCatalog::TUTORIAL_CATALOG_ID);
    $soldier->delete();

    livewire(EditEnemyCatalog::class, [
        'record' => $soldier->getKey(),
    ])
        ->assertActionHidden(ForceDeleteAction::class);

    expect(EnemyCatalog::withTrashed()->find(EnemyCatalog::TUTORIAL_CATALOG_ID))->not->toBeNull();
});

it('clones enemy into create form without primary key', function (): void {
    $source = EnemyCatalog::query()->findOrFail('chance_wanderer');
    $nextId = EnemyCatalog::nextCatalogIdForKind(EnemyKindEnum::MIRROR);

    livewire(ViewEnemyCatalog::class, [
        'record' => $source->getKey(),
    ])
        ->assertActionHasLabel('clone', __('admin.actions.clone.label'))
        ->callAction('clone')
        ->assertRedirect(CreateEnemyCatalog::getUrl());

    livewire(CreateEnemyCatalog::class)
        ->assertSchemaStateSet([
            'catalog_id' => $nextId,
            'name' => $source->name,
            'kind' => $source->kind,
            'power_pct' => $source->power_pct,
        ]);
});

it('rejects guests from enemy resource', function (): void {
    auth()->logout();

    $this->get(ListEnemyCatalog::getUrl())->assertRedirect();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enemyForm(array $overrides): array
{
    return array_merge([
        'name' => 'Тестовый моб',
        'kind' => EnemyKindEnum::FIXED->value,
        'enabled' => true,
        'level' => 1,
        'strength' => 3,
        'agility' => 3,
        'instinct' => 3,
        'vitality' => 3,
        'max_hp' => 50,
        'weapon_damage' => 1,
        'mf_dodge' => 0,
        'mf_anti_dodge' => 0,
        'mf_crit' => 0,
        'mf_anti_crit' => 0,
        'reward_exp_pct' => 30,
        'reward_silver_min' => 10,
        'reward_silver_max' => 20,
    ], $overrides);
}
