<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Filament\Resources\BackpackCatalog\Pages\CreateBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\EditBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ListBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ViewBackpackCatalog;
use App\Models\Backpack\BackpackCatalog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Enums\Alignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('lists and views equipment catalog', function (): void {
    $item = BackpackCatalog::query()->findOrFail('knife_0');

    livewire(ListBackpackCatalog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);

    livewire(ViewBackpackCatalog::class, [
        'record' => $item->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'catalog_id' => 'knife_0',
            'name' => 'Учебный нож',
        ]);
});

it('creates weapon and armor via filament form', function (): void {
    $knifeId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::KNIFE);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Кастомный клинок',
            'description' => 'Тестовый меч из админки',
            'price' => 77,
            'weapon_damage_min' => 6,
            'weapon_damage_max' => 6,
            'mf_dodge' => 1,
            'mf_crit' => 2,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackpackCatalog::query()->findOrFail($knifeId)->price)->toBe(77)
        ->and(shopCatalog()->findItem($knifeId)->weaponDamageMin)->toBe(6)
        ->and(shopCatalog()->findItem($knifeId)->weaponDamageMax)->toBe(6);

    $heavyId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::HEAVY);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(armorForm([
            'name' => 'Кастомная броня',
            'description' => 'Тестовая броня',
            'stat_bonus' => 12,
            'armor' => 3,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackpackCatalog::query()->findOrFail($heavyId)->item_type)->toBe(TypeEnum::ARMOR)
        ->and(shopCatalog()->findItem($heavyId)->statBonus)->toBe(12);

});

it('uploads spatie media art on create', function (): void {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('sword.png', 40, 40);
    $knifeId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::KNIFE);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Клинок с артом',
            'description' => 'С картинкой',
            'image' => [$file],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $created = BackpackCatalog::query()->findOrFail($knifeId);

    expect($created->hasMedia('image'))->toBeTrue();
});

it('validates non-negative price and mf', function (): void {
    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Дубль',
            'description' => 'Плохие числа',
            'price' => -1,
            'mf_dodge' => -2,
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'price',
            'mf_dodge',
        ]);
});

it('edits equipment name and exposes it in catalog', function (): void {
    $item = BackpackCatalog::query()->findOrFail('heavy_0');

    livewire(EditBackpackCatalog::class, [
        'record' => $item->getKey(),
    ])
        ->fillForm([
            'name' => 'Кольчуга героя',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(shopCatalog()->mailShirt()->itemName)->toBe('Кольчуга героя');
});

it('deletes restores and force deletes via edit page actions', function (): void {
    $item = BackpackCatalog::factory()->create([
        'catalog_id' => 'temp_blade',
        'name' => 'Временный клинок',
    ]);

    livewire(EditBackpackCatalog::class, [
        'record' => $item->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(BackpackCatalog::withTrashed()->findOrFail('temp_blade')->trashed())->toBeTrue();

    livewire(EditBackpackCatalog::class, [
        'record' => 'temp_blade',
    ])
        ->callAction(RestoreAction::class);

    expect(BackpackCatalog::query()->findOrFail('temp_blade')->trashed())->toBeFalse();

    livewire(EditBackpackCatalog::class, [
        'record' => 'temp_blade',
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(BackpackCatalog::withTrashed()->where('catalog_id', 'temp_blade')->exists())->toBeFalse();
});

it('hides force delete when equipment is owned', function (): void {
    $character = characters()->createDraft(9105);
    backpack()->addItem($character->tg_id, 'club_0');

    $item = BackpackCatalog::query()->findOrFail('club_0');
    $item->delete();

    livewire(EditBackpackCatalog::class, [
        'record' => $item->getKey(),
    ])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('filters equipment table by type', function (): void {
    $weapon = BackpackCatalog::query()->findOrFail('knife_0');
    $armor = BackpackCatalog::query()->findOrFail('heavy_0');

    livewire(ListBackpackCatalog::class)
        ->filterTable('item_type', TypeEnum::WEAPON->value)
        ->assertCanSeeTableRecords([$weapon])
        ->assertCanNotSeeTableRecords([$armor]);
});

it('filters equipment table by profile', function (): void {
    $weapon = BackpackCatalog::query()->findOrFail('knife_0');
    $armor = BackpackCatalog::query()->findOrFail('heavy_0');

    livewire(ListBackpackCatalog::class)
        ->filterTable('profile', ProfileEnum::HEAVY->value)
        ->assertCanSeeTableRecords([$armor])
        ->assertCanNotSeeTableRecords([$weapon]);
});

it('filters equipment table by enabled', function (): void {
    $weapon = BackpackCatalog::query()->findOrFail('knife_0');

    $weapon->enabled = false;
    $weapon->save();

    livewire(ListBackpackCatalog::class)
        ->filterTable('enabled', true)
        ->assertCanNotSeeTableRecords([$weapon->fresh()]);
});

it('rejects guests from equipment resource', function (): void {
    auth()->logout();

    $this->get(ListBackpackCatalog::getUrl())->assertRedirect();
});

it('clones equipment into create form without primary key', function (): void {
    $source = BackpackCatalog::query()->findOrFail('knife_0');
    $nextId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::KNIFE);

    livewire(ViewBackpackCatalog::class, [
        'record' => $source->getKey(),
    ])
        ->assertActionHasLabel('clone', __('admin.actions.clone.label'))
        ->callAction('clone')
        ->assertRedirect(CreateBackpackCatalog::getUrl());

    livewire(CreateBackpackCatalog::class)
        ->assertSchemaStateSet([
            'catalog_id' => $nextId,
            'name' => $source->name,
            'description' => $source->description,
            'weapon_damage_min' => $source->weapon_damage_min,
            'weapon_damage_max' => $source->weapon_damage_max,
            'price' => $source->price,
            'slot' => $source->slot->value,
            'profile' => $source->profile->value,
        ]);
});

it('creates jewelry with stat bonus and mf without weapon damage', function (): void {
    $focusId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::FOCUS);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(jewelryForm([
            'name' => 'Кастомный амулет',
            'description' => 'Тестовая бижутерия',
            'stat_bonus' => 4,
            'mf_crit' => 3,
        ]))
        ->assertFormFieldVisible('stat_bonus')
        ->assertFormFieldVisible('mf_crit')
        ->assertFormFieldVisible('req_level')
        ->assertFormFieldHidden('weapon_damage_min')
        ->assertFormFieldHidden('weapon_damage_max')
        ->assertFormFieldHidden('armor')
        ->assertFormFieldHidden('max_durability')
        ->assertFormFieldHidden('gem_slots')
        ->assertFormFieldHidden('repairable')
        ->call('create')
        ->assertHasNoFormErrors();

    $created = BackpackCatalog::query()->findOrFail($focusId);

    expect($created->item_type)->toBe(TypeEnum::JEWELRY)
        ->and($created->slot)->toBe(SlotEnum::AMULET)
        ->and($created->stat_bonus)->toBe(4)
        ->and($created->mf_crit)->toBe(3)
        ->and($created->weapon_damage_min)->toBe(0)
        ->and($created->weapon_damage_max)->toBe(0)
        ->and($created->max_durability)->toBeNull()
        ->and($created->gem_slots)->toBeNull()
        ->and($created->repairable)->toBeFalse()
        ->and(shopCatalog()->findItem($focusId)->statBonus)->toBe(4);
});

it('saves gold currency without vip_only flag', function (): void {
    $goldId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::KNIFE);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Золотой клинок',
            'description' => 'За золото',
            'currency' => CurrencyEnum::GOLD,
            'price' => 3,
        ]))
        ->assertFormFieldDoesNotExist('vip_only')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackpackCatalog::query()->findOrFail($goldId)->currency)->toBe(CurrencyEnum::GOLD);
    expect(Schema::hasColumn('backpack_catalog', 'vip_only'))->toBeFalse();
});

it('shows armor fields for armor type and clears weapon damage', function (): void {
    $heavyId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::HEAVY);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Из клинка в броню',
            'description' => 'Смена на броню',
            'weapon_damage_min' => 7,
            'weapon_damage_max' => 7,
        ]))
        ->fillForm([
            'item_type' => TypeEnum::ARMOR,
            'profile' => ProfileEnum::HEAVY,
            'slot' => SlotEnum::ARMOR,
            'stat_bonus' => 11,
            'armor' => 4,
        ])
        ->assertSchemaStateSet([
            'weapon_damage_min' => null,
            'weapon_damage_max' => null,
        ])
        ->assertFormFieldHidden('weapon_damage_min')
        ->assertFormFieldHidden('weapon_damage_max')
        ->assertFormFieldVisible('stat_bonus')
        ->assertFormFieldVisible('armor')
        ->assertFormFieldVisible('mf_dodge')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackpackCatalog::query()->findOrFail($heavyId)->item_type)->toBe(TypeEnum::ARMOR)
        ->and(BackpackCatalog::query()->findOrFail($heavyId)->armor)->toBe(4);
});

it('hides combat fields until item type is selected', function (): void {
    livewire(CreateBackpackCatalog::class)
        ->assertFormFieldHidden('weapon_damage_min')
        ->assertFormFieldHidden('weapon_damage_max')
        ->assertFormFieldHidden('stat_bonus')
        ->assertFormFieldHidden('armor')
        ->assertFormFieldHidden('mf_dodge');
});

it('keeps view form fields disabled', function (): void {
    livewire(ViewBackpackCatalog::class, [
        'record' => 'knife_0',
    ])
        ->assertFormFieldDisabled('name')
        ->assertFormFieldDisabled('price')
        ->assertFormFieldDisabled('weapon_damage_min')
        ->assertFormFieldDisabled('weapon_damage_max');
});

it('aligns create and edit form actions between cancel and submit', function (): void {
    $create = livewire(CreateBackpackCatalog::class);

    expect($create->instance()->getFormActionsAlignment())->toBe(Alignment::Between);

    $create
        ->assertActionExists(TestAction::make('cancel')->schemaComponent('form-actions', schema: 'content'))
        ->assertActionExists(TestAction::make('create')->schemaComponent('form-actions', schema: 'content'));

    $edit = livewire(EditBackpackCatalog::class, [
        'record' => 'knife_0',
    ]);

    expect($edit->instance()->getFormActionsAlignment())->toBe(Alignment::Between);

    $edit
        ->assertActionExists(TestAction::make('cancel')->schemaComponent('form-actions', schema: 'content'))
        ->assertActionExists(TestAction::make('save')->schemaComponent('form-actions', schema: 'content'));
});

it('labels archive restore and delete actions on edit page', function (): void {
    $item = BackpackCatalog::factory()->create([
        'catalog_id' => 'label_blade',
        'name' => 'Клинок для лейблов',
    ]);

    livewire(EditBackpackCatalog::class, [
        'record' => $item->getKey(),
    ])
        ->assertActionHasLabel(DeleteAction::class, __('admin.actions.archive.label'))
        ->assertActionHidden(RestoreAction::class)
        ->assertActionHidden(ForceDeleteAction::class)
        ->callAction(DeleteAction::class);

    livewire(EditBackpackCatalog::class, [
        'record' => 'label_blade',
    ])
        ->assertActionHasLabel(RestoreAction::class, __('admin.actions.restore.label'))
        ->assertActionHasLabel(ForceDeleteAction::class, __('admin.actions.delete.label'))
        ->assertActionHidden(DeleteAction::class);
});

it('archives equipment from list table action', function (): void {
    $item = BackpackCatalog::factory()->create([
        'catalog_id' => 'table_archive_blade',
        'name' => 'Архив из таблицы',
    ]);

    livewire(ListBackpackCatalog::class)
        ->callAction(TestAction::make('delete')->table($item));

    expect(BackpackCatalog::withTrashed()->findOrFail('table_archive_blade')->trashed())->toBeTrue();
});

it('filters archived equipment via trashed filter', function (): void {
    $active = BackpackCatalog::query()->findOrFail('knife_0');
    $archived = BackpackCatalog::factory()->create([
        'catalog_id' => 'archived_blade',
        'name' => 'В архиве',
    ]);
    $archived->delete();

    livewire(ListBackpackCatalog::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived]);

    livewire(ListBackpackCatalog::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$active]);

    livewire(ListBackpackCatalog::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active, $archived]);
});

it('does not expose dropped equipment columns in schema or form', function (): void {
    expect(Schema::hasColumn('backpack_catalog', 'stars_price'))->toBeFalse()
        ->and(Schema::hasColumn('backpack_catalog', 'admin_note'))->toBeFalse()
        ->and(Schema::hasColumn('backpack_catalog', 'req_vitality'))->toBeTrue()
        ->and(Schema::hasColumn('backpack_catalog', 'vip_only'))->toBeFalse()
        ->and(Schema::hasColumn('backpack_catalog', 'effect_type'))->toBeFalse()
        ->and(Schema::hasColumn('backpack_catalog', 'allowed_gem_types'))->toBeFalse();

    livewire(CreateBackpackCatalog::class)
        ->assertFormFieldDoesNotExist('stars_price')
        ->assertFormFieldDoesNotExist('admin_note')
        ->assertFormFieldDoesNotExist('vip_only')
        ->assertFormFieldDoesNotExist('effect_type')
        ->assertFormFieldDoesNotExist('allowed_gem_types')
        ->assertFormFieldDoesNotExist('sort_order')
        ->assertFormFieldExists('req_vitality');
});

it('saves gem slots on weapon', function (): void {
    $knifeId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::KNIFE);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(weaponForm([
            'name' => 'Админский нож',
            'slot' => SlotEnum::RIGHT_HAND,
            'profile' => ProfileEnum::KNIFE,
            'weapon_damage_min' => 3,
            'weapon_damage_max' => 4,
            'gem_slots' => 2,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $item = BackpackCatalog::query()->findOrFail($knifeId);

    expect($item->slot)->toBe(SlotEnum::RIGHT_HAND)
        ->and($item->profile)->toBe(ProfileEnum::KNIFE)
        ->and($item->gem_slots)->toBe(2);
});

it('hides zone armor for gloves and clears it on save', function (): void {
    $mobileId = BackpackCatalog::nextCatalogIdForProfile(ProfileEnum::MOBILE);

    livewire(CreateBackpackCatalog::class)
        ->fillForm(armorForm([
            'name' => 'Админские перчатки',
            'slot' => SlotEnum::GLOVES,
            'profile' => ProfileEnum::MOBILE,
            'armor' => 5,
            'stat_bonus' => 2,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackpackCatalog::query()->findOrFail($mobileId)->armor)->toBe(0);
});

it('offers HEAL and STAMINA potion profiles in enum forType', function (): void {
    expect(ProfileEnum::forType(TypeEnum::POTION))->toBe([ProfileEnum::HEAL, ProfileEnum::STAMINA]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jewelryForm(array $overrides): array
{
    return array_merge(weaponForm([
        'item_type' => TypeEnum::JEWELRY,
        'slot' => SlotEnum::AMULET,
        'profile' => ProfileEnum::FOCUS,
        'weapon_damage_min' => 0,
        'weapon_damage_max' => 0,
        'stat_bonus' => 1,
    ]), $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function weaponForm(array $overrides): array
{
    return array_merge([
        'name' => 'Оружие',
        'description' => 'Описание',
        'item_type' => TypeEnum::WEAPON,
        'slot' => SlotEnum::RIGHT_HAND,
        'profile' => ProfileEnum::KNIFE,
        'enabled' => true,
        'price' => 10,
        'currency' => CurrencyEnum::SILVER,
        'repair_tier' => RepairEnum::NORMAL,
        'weapon_damage_min' => 1,
        'weapon_damage_max' => 1,
        'stat_bonus' => 0,
        'armor' => 0,
        'mf_dodge' => 0,
        'mf_anti_dodge' => 0,
        'mf_crit' => 0,
        'mf_anti_crit' => 0,
        'repairable' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function armorForm(array $overrides): array
{
    return array_merge(weaponForm([
        'item_type' => TypeEnum::ARMOR,
        'slot' => SlotEnum::ARMOR,
        'profile' => ProfileEnum::HEAVY,
        'weapon_damage_min' => 0,
        'weapon_damage_max' => 0,
        'stat_bonus' => 10,
    ]), $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function potionForm(array $overrides): array
{
    return array_merge(weaponForm([
        'item_type' => TypeEnum::POTION,
        'slot' => SlotEnum::POCKET,
        'profile' => ProfileEnum::HEAL,
        'weapon_damage_min' => 0,
        'weapon_damage_max' => 0,
        'effect_value' => 40,
        'repairable' => false,
    ]), $overrides);
}
