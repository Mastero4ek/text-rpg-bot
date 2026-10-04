<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
use App\Models\Inventory;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

it('seeds equipment catalog into the database', function (): void {
    expect(Equipment::query()->count())->toBeGreaterThan(10)
        ->and(shopCatalog()->mailShirtId())->toBe('heavy_0')
        ->and(shopCatalog()->starterKnucklesId())->toBe('knuckles_0')
        ->and(shopCatalog()->freeTrainerItemId())->toBe('club_0')
        ->and(shopCatalog()->potionPrice())->toBe(15)
        ->and(shopCatalog()->potionHeal())->toBe(40)
        ->and(shopCatalog()->findItem('knife_0')->itemName)->toBe('Учебный нож')
        ->and(shopCatalog()->findItem('heal_0')->itemType)->toBe(TypeEnum::POTION)
        ->and(shopCatalog()->findItem('heal_0')->profile)->toBe(ProfileEnum::HEAL)
        ->and(shopCatalog()->findItem('heal_0')->effectValue)->toBe(40);
});

it('lists novice and tier weapons from hardcoded novice ids', function (): void {
    $novice = shopCatalog()->noviceWeapons();
    $tier = shopCatalog()->tierWeapons();

    expect($novice)->toHaveCount(3)
        ->and($tier)->toHaveCount(13)
        ->and(collect($novice)->pluck('itemId')->all())->toContain('knife_0')
        ->and(collect($tier)->pluck('itemId')->all())->toContain('knife_1')
        ->and(collect($tier)->pluck('itemId')->all())->not->toContain('knife_0');
});

it('hides disabled and out-of-shop weapons from shop lists', function (): void {
    $disabled = Equipment::query()->findOrFail('knife_0');
    $disabled->enabled = false;
    $disabled->save();

    $outOfShop = Equipment::query()->findOrFail('knife_1');
    $outOfShop->in_shop = false;
    $outOfShop->save();

    expect(collect(shopCatalog()->noviceWeapons())->pluck('itemId')->all())->not->toContain('knife_0')
        ->and(collect(shopCatalog()->tierWeapons())->pluck('itemId')->all())->not->toContain('knife_1')
        ->and(shopCatalog()->isShopWeapon('knife_0'))->toBeFalse()
        ->and(shopCatalog()->isShopWeapon('knife_1'))->toBeFalse();
});

it('finds trashed and disabled equipment for already owned lookup', function (): void {
    $equipment = Equipment::query()->findOrFail('axe_0');
    $equipment->enabled = false;
    $equipment->save();
    $equipment->delete();

    $def = shopCatalog()->findItem('axe_0');

    expect($def->itemId)->toBe('axe_0')
        ->and($def->itemName)->toBe('Учебный топор')
        ->and(shopCatalog()->hasItem('axe_0'))->toBeTrue()
        ->and(shopCatalog()->isShopWeapon('axe_0'))->toBeFalse();
});

it('syncs inventory item_name when equipment name changes', function (): void {
    $character = characters()->createDraft(7001);
    inventory()->addItem($character->tg_id, 'knife_0');

    $equipment = Equipment::query()->findOrFail('knife_0');
    $equipment->name = 'Новый учебный нож';
    $equipment->save();

    expect(Inventory::query()->where('tg_id', $character->tg_id)->where('item_id', 'knife_0')->value('item_name'))
        ->toBe('Новый учебный нож')
        ->and(shopCatalog()->findItem('knife_0')->itemName)->toBe('Новый учебный нож');
});

it('blocks force delete when item exists in inventories', function (): void {
    $character = characters()->createDraft(7002);
    inventory()->addItem($character->tg_id, 'axe_0');

    $equipment = Equipment::query()->findOrFail('axe_0');
    $equipment->delete();

    expect($equipment->fresh()->trashed())->toBeTrue()
        ->and($equipment->isReferencedByInventory())->toBeTrue()
        ->and(App\Filament\Resources\Equipment\EquipmentResource::canForceDelete($equipment))->toBeFalse();
});

it('combat potion heal reads effect_value from equipment', function (): void {
    expect(combat()->potionHeal())->toBe(40);

    $potion = Equipment::query()->findOrFail('heal_0');
    $potion->effect_value = 55;
    $potion->save();

    expect(combat()->potionHeal())->toBe(55);
});

it('buys weapon for gold vip wallet', function (): void {
    $equipment = Equipment::query()->findOrFail('knife_1');
    $equipment->currency = CurrencyEnum::GOLD;
    $equipment->price = 3;
    $equipment->save();

    $character = characters()->createDraft(7003);
    $character->silver = 0;
    $character->gold = 3;
    $character->save();

    $buy = shopService()->buyWeapon($character->tg_id, 'knife_1');

    expect($buy->ok)->toBeTrue()
        ->and($buy->character->gold)->toBe(0)
        ->and($buy->character->silver)->toBe(0)
        ->and(inventory()->owns($character->tg_id, 'knife_1'))->toBeTrue();

    $poor = characters()->createDraft(7004);
    $poor->silver = 100;
    $poor->gold = 0;
    $poor->save();

    expect(shopService()->buyWeapon($poor->tg_id, 'knife_1')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($poor->tg_id, 'knife_1')->error)->toBe(__('errors.not_enough_gold'));
});

it('rejects buying weapons hidden from shop', function (): void {
    $equipment = Equipment::query()->findOrFail('axe_1');
    $equipment->enabled = false;
    $equipment->save();

    $character = characters()->createDraft(7005);
    $character->silver = 999;
    $character->save();

    expect(shopService()->buyWeapon($character->tg_id, 'axe_1')->ok)->toBeFalse();
});

it('invalidates catalog cache and recovers from corrupt cache payload', function (): void {
    expect(shopCatalog()->findItem('club_0')->weaponDamageMin)->toBe(3)
        ->and(shopCatalog()->findItem('club_0')->weaponDamageMax)->toBe(4);

    $equipment = Equipment::query()->findOrFail('club_0');
    $equipment->weapon_damage_min = 9;
    $equipment->weapon_damage_max = 11;
    $equipment->save();

    expect(shopCatalog()->findItem('club_0')->weaponDamageMin)->toBe(9)
        ->and(shopCatalog()->findItem('club_0')->weaponDamageMax)->toBe(11);

    Cache::put('equipment.catalog.v5', Collection::make([
        'broken' => new class
        {
            public string $x = 'incomplete-like';
        },
    ]), 3600);

    expect(shopCatalog()->findItem('knife_0')->itemId)->toBe('knife_0');
});

it('seeder is idempotent', function (): void {
    $before = Equipment::query()->count();
    $this->seed(EquipmentSeeder::class);

    expect(Equipment::query()->count())->toBe($before);
});
