<?php

declare(strict_types=1);

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Resources\BagCatalog\Pages\CreateBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\EditBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\ListBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\ViewBagCatalog;
use App\Models\Bag\BagCatalog;
use App\Models\User;
use App\Services\Bag\BagCatalog as BagCatalogService;
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
    $gem = BagCatalog::query()->findOrFail('ruby_0');

    livewire(ListBagCatalog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$gem]);

    livewire(ViewBagCatalog::class, [
        'record' => $gem->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'catalog_id' => 'ruby_0',
            'name' => 'Рубин ученика',
        ]);
});

it('creates ruby emerald sapphire and diamond via filament form', function (): void {
    $rubyId = BagCatalog::nextCatalogIdForType(GemTypeEnum::RUBY);

    livewire(CreateBagCatalog::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный рубин',
            'type' => GemTypeEnum::RUBY->value,
            'mf_crit' => 6,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BagCatalog::query()->findOrFail($rubyId)->mf_crit)->toBe(6)
        ->and(app(BagCatalogService::class)->findGem($rubyId)->mf->crit)->toBe(6);

    $emeraldId = BagCatalog::nextCatalogIdForType(GemTypeEnum::EMERALD);

    livewire(CreateBagCatalog::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный изумруд',
            'type' => GemTypeEnum::EMERALD->value,
            'mf_dodge' => 5,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BagCatalog::query()->findOrFail($emeraldId)->type)->toBe(GemTypeEnum::EMERALD)
        ->and(BagCatalog::query()->findOrFail($emeraldId)->mf_dodge)->toBe(5)
        ->and(BagCatalog::query()->findOrFail($emeraldId)->mf_crit)->toBe(0);

    $sapphireId = BagCatalog::nextCatalogIdForType(GemTypeEnum::SAPPHIRE);

    livewire(CreateBagCatalog::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный сапфир',
            'type' => GemTypeEnum::SAPPHIRE->value,
            'mf_anti_crit' => 7,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BagCatalog::query()->findOrFail($sapphireId)->mf_anti_crit)->toBe(7);

    $diamondId = BagCatalog::nextCatalogIdForType(GemTypeEnum::DIAMOND);

    livewire(CreateBagCatalog::class)
        ->fillForm(gemForm([
            'name' => 'Кастомный алмаз',
            'type' => GemTypeEnum::DIAMOND->value,
            'in_shop' => false,
            'mf_anti_dodge' => 9,
            'mf_crit' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BagCatalog::query()->findOrFail($diamondId)->mf_anti_dodge)->toBe(9)
        ->and(BagCatalog::query()->findOrFail($diamondId)->in_shop)->toBeFalse();
});

it('uploads spatie media art on create', function (): void {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('ruby.png', 40, 40);
    $gemId = BagCatalog::nextCatalogIdForType(GemTypeEnum::RUBY);

    livewire(CreateBagCatalog::class)
        ->fillForm(gemForm([
            'name' => 'Рубин с артом',
            'image' => [$file],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BagCatalog::query()->findOrFail($gemId)->hasMedia('image'))->toBeTrue();
});

it('validates non-negative price and mf', function (): void {
    livewire(CreateBagCatalog::class)
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
    $gem = BagCatalog::query()->findOrFail('ruby_0');

    livewire(EditBagCatalog::class, [
        'record' => $gem->getKey(),
    ])
        ->fillForm([
            'name' => 'Рубин героя',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(BagCatalogService::class)->findGem('ruby_0')->name)->toBe('Рубин героя');
});

it('deletes restores and force deletes via edit page actions', function (): void {
    $gem = BagCatalog::factory()->create([
        'catalog_id' => 'temp_ruby',
        'name' => 'Временный рубин',
        'in_shop' => false,
    ]);

    livewire(EditBagCatalog::class, [
        'record' => $gem->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(BagCatalog::withTrashed()->findOrFail('temp_ruby')->trashed())->toBeTrue();

    livewire(EditBagCatalog::class, [
        'record' => 'temp_ruby',
    ])
        ->callAction(RestoreAction::class);

    expect(BagCatalog::query()->findOrFail('temp_ruby')->trashed())->toBeFalse();

    livewire(EditBagCatalog::class, [
        'record' => 'temp_ruby',
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(BagCatalog::withTrashed()->where('catalog_id', 'temp_ruby')->exists())->toBeFalse();
});

it('hides force delete when gem is in pouch', function (): void {
    $character = characters()->createDraft(9201);
    grantGem($character, 'ruby_0', 1);

    $gem = BagCatalog::query()->findOrFail('ruby_0');
    $gem->delete();

    livewire(EditBagCatalog::class, [
        'record' => $gem->getKey(),
    ])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('filters gem table by profile and shop flags', function (): void {
    $ruby = BagCatalog::query()->findOrFail('ruby_0');
    $diamond = BagCatalog::query()->findOrFail('diamond_0');

    livewire(ListBagCatalog::class)
        ->filterTable('profile_display', GemTypeEnum::RUBY->value)
        ->assertCanSeeTableRecords([$ruby])
        ->assertCanNotSeeTableRecords([$diamond]);

    livewire(ListBagCatalog::class)
        ->filterTable('in_shop', true)
        ->assertCanSeeTableRecords([$ruby])
        ->assertCanNotSeeTableRecords([$diamond]);
});

it('rejects guests from gem resource', function (): void {
    auth()->logout();

    $this->get(ListBagCatalog::getUrl())->assertRedirect();
});

it('clones gem into create form without primary key', function (): void {
    $source = BagCatalog::query()->findOrFail('ruby_0');
    $nextId = BagCatalog::nextCatalogIdForType(GemTypeEnum::RUBY);

    livewire(ViewBagCatalog::class, [
        'record' => $source->getKey(),
    ])
        ->assertActionHasLabel('clone', __('admin.actions.clone.label'))
        ->callAction('clone')
        ->assertRedirect(CreateBagCatalog::getUrl());

    livewire(CreateBagCatalog::class)
        ->assertSchemaStateSet([
            'catalog_id' => $nextId,
            'name' => $source->name,
            'mf_crit' => $source->mf_crit,
            'price' => $source->price,
            'type' => $source->type,
        ]);
});

it('resets irrelevant mf fields when type changes', function (): void {
    livewire(CreateBagCatalog::class)
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

    $created = BagCatalog::query()->where('name', 'Смена типа')->firstOrFail();

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
        'kind' => BagKindEnum::GEM->value,
        'type' => GemTypeEnum::RUBY->value,
        'enabled' => true,
        'in_shop' => true,
        'currency' => CurrencyEnum::SILVER->value,
        'price' => 25,
        'max_durability' => 10,
        'mf_crit' => 4,
    ], $overrides);
}
