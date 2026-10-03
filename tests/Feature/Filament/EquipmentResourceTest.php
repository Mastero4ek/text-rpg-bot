<?php

declare(strict_types=1);

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\EquipmentProfileEnum;
use App\Enums\Equipment\RepairTierEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Resources\Equipment\Pages\ViewEquipment;
use App\Models\Equipment;
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
    $item = Equipment::query()->findOrFail('train_knife');

    livewire(ListEquipment::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);

    livewire(ViewEquipment::class, [
        'record' => $item->getKey(),
    ])
        ->assertOk()
        ->assertSchemaStateSet([
            'item_id' => 'train_knife',
            'name' => 'Учебный нож',
        ]);
});

it('creates weapon armor and potion via filament form', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'custom_blade',
            'name' => 'Кастомный клинок',
            'description' => 'Тестовый меч из админки',
            'price' => 77,
            'weapon_damage' => 6,
            'mf_dodge' => 1,
            'mf_crit' => 2,
            'sort_order' => 99,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('custom_blade')->price)->toBe(77)
        ->and(shopCatalog()->findItem('custom_blade')->weaponDamage)->toBe(6);

    livewire(CreateEquipment::class)
        ->fillForm(armorForm([
            'item_id' => 'custom_mail',
            'name' => 'Кастомная броня',
            'description' => 'Тестовая броня',
            'stat_bonus' => 12,
            'armor' => 3,
            'sort_order' => 100,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('custom_mail')->item_type)->toBe(TypeEnum::ARMOR)
        ->and(shopCatalog()->findItem('custom_mail')->statBonus)->toBe(12);

    livewire(CreateEquipment::class)
        ->fillForm(potionForm([
            'item_id' => 'custom_potion',
            'name' => 'Кастомное зелье',
            'description' => 'Тестовое зелье',
            'price' => 25,
            'effect_value' => 33,
            'sort_order' => 101,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('custom_potion')->item_type)->toBe(TypeEnum::POTION)
        ->and(shopCatalog()->findItem('custom_potion')->effectValue)->toBe(33);
});

it('uploads spatie media art on create', function (): void {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('sword.png', 40, 40);

    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'art_blade',
            'name' => 'Клинок с артом',
            'description' => 'С картинкой',
            'image' => [$file],
            'sort_order' => 110,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Equipment::query()->findOrFail('art_blade');

    expect($created->hasMedia('image'))->toBeTrue();
});

it('validates unique item_id and non-negative price and mf', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'train_knife',
            'name' => 'Дубль',
            'description' => 'Уже есть',
            'price' => -1,
            'mf_dodge' => -2,
            'sort_order' => 120,
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'item_id',
            'price',
            'mf_dodge',
        ]);
});

it('edits equipment name and exposes it in catalog', function (): void {
    $item = Equipment::query()->findOrFail('mail_shirt');

    livewire(EditEquipment::class, [
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
    $item = Equipment::factory()->create([
        'item_id' => 'temp_blade',
        'name' => 'Временный клинок',
        'in_shop' => false,
    ]);

    livewire(EditEquipment::class, [
        'record' => $item->getKey(),
    ])
        ->callAction(DeleteAction::class);

    expect(Equipment::withTrashed()->findOrFail('temp_blade')->trashed())->toBeTrue();

    livewire(EditEquipment::class, [
        'record' => 'temp_blade',
    ])
        ->callAction(RestoreAction::class);

    expect(Equipment::query()->findOrFail('temp_blade')->trashed())->toBeFalse();

    livewire(EditEquipment::class, [
        'record' => 'temp_blade',
    ])
        ->callAction(DeleteAction::class)
        ->callAction(ForceDeleteAction::class);

    expect(Equipment::withTrashed()->where('item_id', 'temp_blade')->exists())->toBeFalse();
});

it('hides force delete when equipment is owned', function (): void {
    $character = characters()->createDraft(9105);
    inventory()->addItem($character->tg_id, 'train_club');

    $item = Equipment::query()->findOrFail('train_club');
    $item->delete();

    livewire(EditEquipment::class, [
        'record' => $item->getKey(),
    ])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('filters equipment table by type', function (): void {
    $weapon = Equipment::query()->findOrFail('train_knife');
    $armor = Equipment::query()->findOrFail('mail_shirt');

    livewire(ListEquipment::class)
        ->filterTable('item_type', TypeEnum::WEAPON->value)
        ->assertCanSeeTableRecords([$weapon])
        ->assertCanNotSeeTableRecords([$armor]);
});

it('filters equipment table by slot', function (): void {
    $weapon = Equipment::query()->findOrFail('train_knife');
    $armor = Equipment::query()->findOrFail('mail_shirt');

    livewire(ListEquipment::class)
        ->filterTable('slot', SlotEnum::ARMOR->value)
        ->assertCanSeeTableRecords([$armor])
        ->assertCanNotSeeTableRecords([$weapon]);
});

it('filters equipment table by tier', function (): void {
    $tierWeapon = Equipment::query()->findOrFail('knife_t1');
    $novice = Equipment::query()->findOrFail('train_knife');

    livewire(ListEquipment::class)
        ->filterTable('tier', 1)
        ->assertCanSeeTableRecords([$tierWeapon])
        ->assertCanNotSeeTableRecords([$novice]);
});

it('filters equipment table by in_shop and enabled', function (): void {
    $weapon = Equipment::query()->findOrFail('train_knife');
    $armor = Equipment::query()->findOrFail('mail_shirt');

    livewire(ListEquipment::class)
        ->filterTable('in_shop', true)
        ->assertCanSeeTableRecords([$weapon])
        ->assertCanNotSeeTableRecords([$armor]);

    $weapon->enabled = false;
    $weapon->save();

    livewire(ListEquipment::class)
        ->filterTable('enabled', true)
        ->assertCanNotSeeTableRecords([$weapon->fresh()]);
});

it('rejects guests from equipment resource', function (): void {
    auth()->logout();

    $this->get(ListEquipment::getUrl())->assertRedirect();
});

it('clones equipment into create form without primary key', function (): void {
    $source = Equipment::query()->findOrFail('train_knife');

    livewire(ViewEquipment::class, [
        'record' => $source->getKey(),
    ])
        ->assertActionHasLabel('clone', __('admin.actions.clone.label'))
        ->callAction('clone')
        ->assertRedirect(CreateEquipment::getUrl());

    livewire(CreateEquipment::class)
        ->assertSchemaStateSet([
            'item_id' => null,
            'name' => $source->name,
            'description' => $source->description,
            'weapon_damage' => $source->weapon_damage,
            'price' => $source->price,
            'slot' => $source->slot->value,
            'profile' => $source->profile->value,
        ]);
});

it('creates jewelry with stat bonus and mf without weapon damage', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(jewelryForm([
            'item_id' => 'custom_amulet',
            'name' => 'Кастомный амулет',
            'description' => 'Тестовая бижутерия',
            'stat_bonus' => 4,
            'mf_crit' => 3,
            'sort_order' => 130,
        ]))
        ->assertFormFieldVisible('stat_bonus')
        ->assertFormFieldVisible('mf_crit')
        ->assertFormFieldHidden('weapon_damage')
        ->assertFormFieldHidden('armor')
        ->assertFormFieldHidden('effect_type')
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Equipment::query()->findOrFail('custom_amulet');

    expect($created->item_type)->toBe(TypeEnum::JEWELRY)
        ->and($created->slot)->toBe(SlotEnum::AMULET)
        ->and($created->stat_bonus)->toBe(4)
        ->and($created->mf_crit)->toBe(3)
        ->and($created->weapon_damage)->toBe(0)
        ->and(shopCatalog()->findItem('custom_amulet')->statBonus)->toBe(4);
});

it('forces vip_only when currency is gold and clears it for silver', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'gold_blade',
            'name' => 'Золотой клинок',
            'description' => 'За золото',
            'sort_order' => 140,
        ]))
        ->fillForm([
            'currency' => CurrencyEnum::GOLD,
        ])
        ->assertSchemaStateSet([
            'vip_only' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('gold_blade'))
        ->currency->toBe(CurrencyEnum::GOLD)
        ->vip_only->toBeTrue();

    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'silver_again',
            'name' => 'Серебряный клинок',
            'description' => 'Не VIP',
            'sort_order' => 141,
        ]))
        ->fillForm([
            'currency' => CurrencyEnum::GOLD,
        ])
        ->fillForm([
            'currency' => CurrencyEnum::SILVER,
        ])
        ->assertSchemaStateSet([
            'vip_only' => false,
        ]);
});

it('resets combat fields and toggles visibility when type changes to potion', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'type_switch_blade',
            'name' => 'Переключатель',
            'description' => 'Смена типа',
            'weapon_damage' => 8,
            'mf_dodge' => 5,
            'sort_order' => 150,
        ]))
        ->assertFormFieldVisible('weapon_damage')
        ->assertFormFieldVisible('mf_dodge')
        ->assertFormFieldHidden('effect_type')
        ->fillForm([
            'item_type' => TypeEnum::POTION,
        ])
        ->assertSchemaStateSet([
            'weapon_damage' => 0,
            'stat_bonus' => 0,
            'armor' => 0,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
            'slot' => SlotEnum::POCKET->value,
            'profile' => null,
        ])
        ->assertFormFieldHidden('weapon_damage')
        ->assertFormFieldHidden('mf_dodge')
        ->assertFormFieldVisible('effect_type')
        ->assertFormFieldVisible('effect_value')
        ->fillForm([
            'profile' => EquipmentProfileEnum::HEAL,
            'effect_value' => 40,
            'name' => 'Зелье из оружия',
            'description' => 'После смены типа',
            'repairable' => false,
            'tier' => null,
        ])
        ->assertSchemaStateSet([
            'effect_type' => EffectTypeEnum::HEAL_HP,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('type_switch_blade')->item_type)->toBe(TypeEnum::POTION)
        ->and(shopCatalog()->findItem('type_switch_blade')->effectValue)->toBe(40);
});

it('shows armor fields for armor type and clears weapon damage', function (): void {
    livewire(CreateEquipment::class)
        ->fillForm(weaponForm([
            'item_id' => 'type_switch_mail',
            'name' => 'Из клинка в броню',
            'description' => 'Смена на броню',
            'weapon_damage' => 7,
            'sort_order' => 151,
        ]))
        ->fillForm([
            'item_type' => TypeEnum::ARMOR,
            'profile' => EquipmentProfileEnum::HEAVY,
            'slot' => SlotEnum::ARMOR,
            'stat_bonus' => 11,
            'armor' => 4,
        ])
        ->assertSchemaStateSet([
            'weapon_damage' => 0,
            'effect_type' => null,
            'effect_value' => null,
        ])
        ->assertFormFieldHidden('weapon_damage')
        ->assertFormFieldHidden('effect_type')
        ->assertFormFieldVisible('stat_bonus')
        ->assertFormFieldVisible('armor')
        ->assertFormFieldVisible('mf_dodge')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->findOrFail('type_switch_mail')->item_type)->toBe(TypeEnum::ARMOR)
        ->and(Equipment::query()->findOrFail('type_switch_mail')->armor)->toBe(4);
});

it('hides combat fields until item type is selected', function (): void {
    livewire(CreateEquipment::class)
        ->assertFormFieldHidden('weapon_damage')
        ->assertFormFieldHidden('stat_bonus')
        ->assertFormFieldHidden('armor')
        ->assertFormFieldHidden('effect_type')
        ->assertFormFieldHidden('mf_dodge');
});

it('keeps view form fields disabled', function (): void {
    livewire(ViewEquipment::class, [
        'record' => 'train_knife',
    ])
        ->assertFormFieldDisabled('name')
        ->assertFormFieldDisabled('price')
        ->assertFormFieldDisabled('weapon_damage');
});

it('aligns create and edit form actions between cancel and submit', function (): void {
    $create = livewire(CreateEquipment::class);

    expect($create->instance()->getFormActionsAlignment())->toBe(Alignment::Between);

    $create
        ->assertActionExists(TestAction::make('cancel')->schemaComponent('form-actions', schema: 'content'))
        ->assertActionExists(TestAction::make('create')->schemaComponent('form-actions', schema: 'content'));

    $edit = livewire(EditEquipment::class, [
        'record' => 'train_knife',
    ]);

    expect($edit->instance()->getFormActionsAlignment())->toBe(Alignment::Between);

    $edit
        ->assertActionExists(TestAction::make('cancel')->schemaComponent('form-actions', schema: 'content'))
        ->assertActionExists(TestAction::make('save')->schemaComponent('form-actions', schema: 'content'));
});

it('labels archive restore and delete actions on edit page', function (): void {
    $item = Equipment::factory()->create([
        'item_id' => 'label_blade',
        'name' => 'Клинок для лейблов',
        'in_shop' => false,
    ]);

    livewire(EditEquipment::class, [
        'record' => $item->getKey(),
    ])
        ->assertActionHasLabel(DeleteAction::class, __('admin.actions.archive.label'))
        ->assertActionHidden(RestoreAction::class)
        ->assertActionHidden(ForceDeleteAction::class)
        ->callAction(DeleteAction::class);

    livewire(EditEquipment::class, [
        'record' => 'label_blade',
    ])
        ->assertActionHasLabel(RestoreAction::class, __('admin.actions.restore.label'))
        ->assertActionHasLabel(ForceDeleteAction::class, __('admin.actions.delete.label'))
        ->assertActionHidden(DeleteAction::class);
});

it('archives equipment from list table action', function (): void {
    $item = Equipment::factory()->create([
        'item_id' => 'table_archive_blade',
        'name' => 'Архив из таблицы',
        'in_shop' => false,
    ]);

    livewire(ListEquipment::class)
        ->callAction(TestAction::make('delete')->table($item));

    expect(Equipment::withTrashed()->findOrFail('table_archive_blade')->trashed())->toBeTrue();
});

it('filters archived equipment via trashed filter', function (): void {
    $active = Equipment::query()->findOrFail('train_knife');
    $archived = Equipment::factory()->create([
        'item_id' => 'archived_blade',
        'name' => 'В архиве',
        'in_shop' => false,
    ]);
    $archived->delete();

    livewire(ListEquipment::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived]);

    livewire(ListEquipment::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$active]);

    livewire(ListEquipment::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active, $archived]);
});

it('does not expose dropped equipment columns in schema or form', function (): void {
    expect(Schema::hasColumn('equipment', 'stars_price'))->toBeFalse()
        ->and(Schema::hasColumn('equipment', 'admin_note'))->toBeFalse()
        ->and(Schema::hasColumn('equipment', 'req_vitality'))->toBeFalse();

    livewire(CreateEquipment::class)
        ->assertFormFieldDoesNotExist('stars_price')
        ->assertFormFieldDoesNotExist('admin_note')
        ->assertFormFieldDoesNotExist('req_vitality')
        ->assertFormFieldDoesNotExist('sort_order');
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
        'profile' => EquipmentProfileEnum::FOCUS,
        'weapon_damage' => 0,
        'stat_bonus' => 1,
        'in_shop' => false,
    ]), $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function weaponForm(array $overrides): array
{
    return array_merge([
        'item_id' => 'form_weapon',
        'name' => 'Оружие',
        'description' => 'Описание',
        'item_type' => TypeEnum::WEAPON,
        'slot' => SlotEnum::RIGHT_HAND,
        'profile' => EquipmentProfileEnum::LIGHT,
        'tier' => 1,
        'in_shop' => true,
        'enabled' => true,
        'price' => 10,
        'currency' => CurrencyEnum::SILVER,
        'vip_only' => false,
        'repair_tier' => RepairTierEnum::NORMAL,
        'weapon_damage' => 1,
        'stat_bonus' => 0,
        'armor' => 0,
        'mf_dodge' => 0,
        'mf_anti_dodge' => 0,
        'mf_crit' => 0,
        'mf_anti_crit' => 0,
        'sort_order' => 0,
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
        'profile' => EquipmentProfileEnum::HEAVY,
        'weapon_damage' => 0,
        'stat_bonus' => 10,
        'in_shop' => false,
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
        'profile' => EquipmentProfileEnum::HEAL,
        'tier' => null,
        'weapon_damage' => 0,
        'effect_type' => EffectTypeEnum::HEAL_HP,
        'effect_value' => 40,
        'repairable' => false,
    ]), $overrides);
}
