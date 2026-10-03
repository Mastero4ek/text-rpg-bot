<?php

declare(strict_types=1);

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
use App\Models\Inventory;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

it('seeds equipment catalog into the database', function (): void {
    expect(Equipment::query()->count())->toBeGreaterThan(10)
        ->and(shopCatalog()->mailShirtId())->toBe('mail_shirt')
        ->and(shopCatalog()->freeTrainerItemId())->toBe('train_club')
        ->and(shopCatalog()->potionPrice())->toBe(15)
        ->and(shopCatalog()->potionHeal())->toBe(40)
        ->and(shopCatalog()->findItem('train_knife')->itemName)->toBe('Учебный нож')
        ->and(shopCatalog()->findItem('health_potion')->itemType)->toBe(TypeEnum::POTION)
        ->and(shopCatalog()->findItem('health_potion')->effectType)->toBe(EffectTypeEnum::HEAL_HP);
});

it('lists novice and tier weapons from hardcoded novice ids', function (): void {
    $novice = shopCatalog()->noviceWeapons();
    $tier = shopCatalog()->tierWeapons();

    expect($novice)->toHaveCount(3)
        ->and($tier)->toHaveCount(9)
        ->and(collect($novice)->pluck('itemId')->all())->toContain('train_knife')
        ->and(collect($tier)->pluck('itemId')->all())->toContain('knife_t1')
        ->and(collect($tier)->pluck('itemId')->all())->not->toContain('train_knife');
});

it('hides disabled and out-of-shop weapons from shop lists', function (): void {
    $disabled = Equipment::query()->findOrFail('train_knife');
    $disabled->enabled = false;
    $disabled->save();

    $outOfShop = Equipment::query()->findOrFail('knife_t1');
    $outOfShop->in_shop = false;
    $outOfShop->save();

    expect(collect(shopCatalog()->noviceWeapons())->pluck('itemId')->all())->not->toContain('train_knife')
        ->and(collect(shopCatalog()->tierWeapons())->pluck('itemId')->all())->not->toContain('knife_t1')
        ->and(shopCatalog()->isShopWeapon('train_knife'))->toBeFalse()
        ->and(shopCatalog()->isShopWeapon('knife_t1'))->toBeFalse();
});

it('finds trashed and disabled equipment for already owned lookup', function (): void {
    $equipment = Equipment::query()->findOrFail('train_axe');
    $equipment->enabled = false;
    $equipment->save();
    $equipment->delete();

    $def = shopCatalog()->findItem('train_axe');

    expect($def->itemId)->toBe('train_axe')
        ->and($def->itemName)->toBe('Учебный топор')
        ->and(shopCatalog()->hasItem('train_axe'))->toBeTrue()
        ->and(shopCatalog()->isShopWeapon('train_axe'))->toBeFalse();
});

it('syncs inventory item_name when equipment name changes', function (): void {
    $character = characters()->createDraft(7001);
    inventory()->addItem($character->tg_id, 'train_knife');

    $equipment = Equipment::query()->findOrFail('train_knife');
    $equipment->name = 'Новый учебный нож';
    $equipment->save();

    expect(Inventory::query()->where('tg_id', $character->tg_id)->value('item_name'))
        ->toBe('Новый учебный нож')
        ->and(shopCatalog()->findItem('train_knife')->itemName)->toBe('Новый учебный нож');
});

it('blocks force delete when item exists in inventories', function (): void {
    $character = characters()->createDraft(7002);
    inventory()->addItem($character->tg_id, 'train_axe');

    $equipment = Equipment::query()->findOrFail('train_axe');
    $equipment->delete();

    expect($equipment->fresh()->trashed())->toBeTrue()
        ->and($equipment->isReferencedByInventory())->toBeTrue()
        ->and(App\Filament\Resources\Equipment\EquipmentResource::canForceDelete($equipment))->toBeFalse();
});

it('combat potion heal reads effect_value from equipment', function (): void {
    expect(combat()->potionHeal())->toBe(40);

    $potion = Equipment::query()->findOrFail('health_potion');
    $potion->effect_value = 55;
    $potion->save();

    expect(combat()->potionHeal())->toBe(55);
});

it('buys weapon for gold vip wallet', function (): void {
    $equipment = Equipment::query()->findOrFail('knife_t1');
    $equipment->currency = CurrencyEnum::GOLD;
    $equipment->price = 3;
    $equipment->save();

    $character = characters()->createDraft(7003);
    $character->silver = 0;
    $character->gold = 3;
    $character->save();

    $buy = shopService()->buyWeapon($character->tg_id, 'knife_t1');

    expect($buy->ok)->toBeTrue()
        ->and($buy->character->gold)->toBe(0)
        ->and($buy->character->silver)->toBe(0)
        ->and(inventory()->owns($character->tg_id, 'knife_t1'))->toBeTrue();

    $poor = characters()->createDraft(7004);
    $poor->silver = 100;
    $poor->gold = 0;
    $poor->save();

    expect(shopService()->buyWeapon($poor->tg_id, 'knife_t1')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($poor->tg_id, 'knife_t1')->error)->toBe(__('errors.not_enough_gold'));
});

it('rejects buying weapons hidden from shop', function (): void {
    $equipment = Equipment::query()->findOrFail('axe_t1');
    $equipment->enabled = false;
    $equipment->save();

    $character = characters()->createDraft(7005);
    $character->silver = 999;
    $character->save();

    expect(shopService()->buyWeapon($character->tg_id, 'axe_t1')->ok)->toBeFalse();
});

it('invalidates catalog cache and recovers from corrupt cache payload', function (): void {
    expect(shopCatalog()->findItem('train_club')->weaponDamage)->toBe(3);

    $equipment = Equipment::query()->findOrFail('train_club');
    $equipment->weapon_damage = 9;
    $equipment->save();

    expect(shopCatalog()->findItem('train_club')->weaponDamage)->toBe(9);

    Cache::put('equipment.catalog.v5', Collection::make([
        'broken' => new class
        {
            public string $x = 'incomplete-like';
        },
    ]), 3600);

    expect(shopCatalog()->findItem('train_knife')->itemId)->toBe('train_knife');
});

it('seeder is idempotent', function (): void {
    $before = Equipment::query()->count();
    $this->seed(EquipmentSeeder::class);

    expect(Equipment::query()->count())->toBe($before);
});
