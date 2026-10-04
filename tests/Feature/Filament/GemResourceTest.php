<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Resources\Gems\Pages\CreateGem;
use App\Filament\Resources\Gems\Pages\EditGem;
use App\Filament\Resources\Gems\Pages\ListGems;
use App\Filament\Resources\Gems\Pages\ViewGem;
use App\Models\Gem;
use App\Models\User;
use App\Services\Gem\GemCatalog;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists and views gem catalog', function (): void {
    $gem = Gem::query()->findOrFail('ruby_0');

    livewire(ListGems::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$gem]);

    livewire(ViewGem::class, [
        'record' => $gem->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'gem_id' => 'ruby_0',
            'name' => 'Рубин новичка',
        ]);
});

it('creates ruby emerald sapphire and diamond via filament form', function (): void {
    $rubyId = Gem::nextGemIdForType(GemTypeEnum::RUBY);

    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный рубин',
            'type' => GemTypeEnum::RUBY->value,
            'mf_crit' => 6,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Gem::query()->findOrFail($rubyId)->mf_crit)->toBe(6)
        ->and(app(GemCatalog::class)->find($rubyId)->mf->crit)->toBe(6);

    $emeraldId = Gem::nextGemIdForType(GemTypeEnum::EMERALD);

    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный изумруд',
            'type' => GemTypeEnum::EMERALD->value,
            'mf_dodge' => 5,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Gem::query()->findOrFail($emeraldId)->type)->toBe(GemTypeEnum::EMERALD)
        ->and(Gem::query()->findOrFail($emeraldId)->mf_dodge)->toBe(5)
        ->and(Gem::query()->findOrFail($emeraldId)->mf_crit)->toBe(0);

    $sapphireId = Gem::nextGemIdForType(GemTypeEnum::SAPPHIRE);

    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный сапфир',
            'type' => GemTypeEnum::SAPPHIRE->value,
            'mf_anti_crit' => 7,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Gem::query()->findOrFail($sapphireId)->mf_anti_crit)->toBe(7);

    $diamondId = Gem::nextGemIdForType(GemTypeEnum::DIAMOND);

    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный алмаз',
            'type' => GemTypeEnum::DIAMOND->value,
            'in_shop' => false,
            'mf_anti_dodge' => 9,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Gem::query()->findOrFail($diamondId)->mf_anti_dodge)->toBe(9)
        ->and(Gem::query()->findOrFail($diamondId)->in_shop)->toBeFalse();
});

it('uploads spatie media art on create', function (): void {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('ruby.png', 40, 40);
    $gemId = Gem::nextGemIdForType(GemTypeEnum::RUBY);

    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Рубин с артом',
            'image' => [$file],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Gem::query()->findOrFail($gemId)->hasMedia('image'))->toBeTrue();
});

it('validates non-negative price and mf', function (): void {
    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Плохой',
            'price' => -1,
            'mf_crit' => -2,
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'price',
            'mf_crit',
        ]);
});

it('edits gem name and exposes it in catalog', function (): void {
    $gem = Gem::query()->findOrFail('ruby_0');

    livewire(EditGem::class, [
        'record' => $gem->getKey(),
    ])
        ->fillForm([
            'name' => 'Рубин героя',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GemCatalog::class)->find('ruby_0')->name)->toBe('Рубин героя');
});

it('deletes restores and force deletes via edit page actions', function (): void {
    $gem = Gem::factory()->create([
        'gem_id' => 'temp_ruby',
        'name' => 'Временный рубин',
        'in_shop' => false,
    ]);

    livewire(EditGem::class, [
        'record' => $gem->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(Gem::withTrashed()->findOrFail('temp_ruby')->trashed())->toBeTrue();

    livewire(EditGem::class, [
        'record' => 'temp_ruby',
    ])
        ->callAction(RestoreAction::class);

    expect(Gem::query()->findOrFail('temp_ruby')->trashed())->toBeFalse();

    livewire(EditGem::class, [
        'record' => 'temp_ruby',
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(Gem::withTrashed()->where('gem_id', 'temp_ruby')->exists())->toBeFalse();
});

it('hides force delete when gem is in pouch', function (): void {
    $character = characters()->createDraft(9201);
    $character->gem_pouch = gemPouch('ruby_0');
    $character->save();

    $gem = Gem::query()->findOrFail('ruby_0');
    $gem->delete();

    livewire(EditGem::class, [
        'record' => $gem->getKey(),
    ])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('filters gem table by type and shop flags', function (): void {
    $ruby = Gem::query()->findOrFail('ruby_0');
    $diamond = Gem::query()->findOrFail('diamond_0');

    livewire(ListGems::class)
        ->filterTable('type', GemTypeEnum::RUBY->value)
        ->assertCanSeeTableRecords([$ruby])
        ->assertCanNotSeeTableRecords([$diamond]);

    livewire(ListGems::class)
        ->filterTable('in_shop', true)
        ->assertCanSeeTableRecords([$ruby])
        ->assertCanNotSeeTableRecords([$diamond]);
});

it('rejects guests from gem resource', function (): void {
    auth()->logout();

    $this->get(ListGems::getUrl())->assertRedirect();
});

it('clones gem into create form without primary key', function (): void {
    $source = Gem::query()->findOrFail('ruby_0');
    $nextId = Gem::nextGemIdForType(GemTypeEnum::RUBY);

    livewire(ViewGem::class, [
        'record' => $source->getKey(),
    ])
        ->assertActionHasLabel('clone', __('admin.actions.clone.label'))
        ->callAction('clone')
        ->assertRedirect(CreateGem::getUrl());

    livewire(CreateGem::class)
        ->assertSchemaStateSet([
            'gem_id' => $nextId,
            'name' => $source->name,
            'mf_crit' => $source->mf_crit,
            'price' => $source->price,
            'type' => $source->type,
        ]);
});

it('resets irrelevant mf fields when type changes', function (): void {
    livewire(CreateGem::class)
        ->fillForm(gemForm([
            'name' => 'Смена типа',
            'type' => GemTypeEnum::RUBY->value,
            'mf_crit' => 4,
        ]))
        ->fillForm([
            'type' => GemTypeEnum::EMERALD->value,
            'mf_dodge' => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Gem::query()->where('name', 'Смена типа')->firstOrFail();

    expect($created->type)->toBe(GemTypeEnum::EMERALD)
        ->and($created->mf_dodge)->toBe(5)
        ->and($created->mf_crit)->toBe(0);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gemForm(array $overrides): array
{
    return array_merge([
        'name' => 'Тестовый камень',
        'description' => 'Описание',
        'type' => GemTypeEnum::RUBY->value,
        'enabled' => true,
        'in_shop' => true,
        'currency' => CurrencyEnum::SILVER->value,
        'price' => 25,
        'max_durability' => 10,
        'mf_crit' => 4,
    ], $overrides);
}
