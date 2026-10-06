<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;
use Database\Seeders\BackpackCatalogSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

it('seeds equipment catalog into the database', function (): void {
    expect(BackpackCatalog::query()->count())->toBeGreaterThan(10)
        ->and(shopCatalog()->mailShirtId())->toBe('heavy_0')
        ->and(shopCatalog()->starterKnucklesId())->toBe('knuckles_0')
        ->and(shopCatalog()->freeTrainerItemId())->toBe('club_0')
        ->and(bagCatalog()->potionPrice())->toBe(15)
        ->and(bagCatalog()->potionHeal())->toBe(40)
        ->and(shopCatalog()->findItem('knife_0')->itemName)->toBe('Учебный нож')
        ->and(bagCatalog()->findPotion('heal_0')->profile)->toBe(ProfileEnum::HEAL)
        ->and(bagCatalog()->findPotion('heal_0')->effectValue)->toBe(40)
        ->and(bagCatalog()->findPotion('stamina_0')->profile)->toBe(ProfileEnum::STAMINA)
        ->and(bagCatalog()->potionStaminaHeal())->toBe(25);
});

it('lists novice and tier weapons from hardcoded novice ids', function (): void {
    $novice = shopCatalog()->noviceWeapons();
    $tier = shopCatalog()->tierWeapons();

    expect($novice)->toHaveCount(3)
        ->and($tier)->toHaveCount(2)
        ->and(collect($novice)->pluck('itemId')->all())->toContain('knife_0')
        ->and(collect($tier)->pluck('itemId')->all())->toContain('sword_0')
        ->and(collect($tier)->pluck('itemId')->all())->not->toContain('knife_0');
});

it('hides disabled and out-of-shop weapons from shop lists', function (): void {
    $disabled = BackpackCatalog::query()->findOrFail('knife_0');
    $disabled->enabled = false;
    $disabled->save();

    $outOfShop = BackpackCatalog::query()->findOrFail('sword_0');
    $outOfShop->cities()->sync([]);
    $outOfShop->save();

    expect(collect(shopCatalog()->noviceWeapons())->pluck('itemId')->all())->not->toContain('knife_0')
        ->and(collect(shopCatalog()->tierWeapons())->pluck('itemId')->all())->not->toContain('sword_0')
        ->and(shopCatalog()->isShopWeapon('knife_0'))->toBeFalse()
        ->and(shopCatalog()->isShopWeapon('sword_0'))->toBeFalse();
});

it('finds trashed and disabled equipment for already owned lookup', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('axe_0');
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
    backpack()->addItem($character->tg_id, 'knife_0');

    $equipment = BackpackCatalog::query()->findOrFail('knife_0');
    $equipment->name = 'Новый учебный нож';
    $equipment->save();

    expect(BackpackItem::query()->where('tg_id', $character->tg_id)->where('catalog_id', 'knife_0')->value('item_name'))
        ->toBe('Новый учебный нож')
        ->and(shopCatalog()->findItem('knife_0')->itemName)->toBe('Новый учебный нож');
});

it('blocks force delete when item exists in backpack', function (): void {
    $character = characters()->createDraft(7002);
    backpack()->addItem($character->tg_id, 'axe_0');

    $equipment = BackpackCatalog::query()->findOrFail('axe_0');
    $equipment->delete();

    expect($equipment->fresh()->trashed())->toBeTrue()
        ->and($equipment->isReferencedByBackpack())->toBeTrue()
        ->and(App\Filament\Resources\BackpackCatalog\BackpackCatalogResource::canForceDelete($equipment))->toBeFalse();
});

it('combat potion heal reads effect_value from bag catalog', function (): void {
    expect(combat()->potionHeal())->toBe(40);

    $potion = App\Models\Bag\BagCatalog::query()->findOrFail('heal_0');
    $potion->effect_value = 55;
    $potion->save();

    expect(combat()->potionHeal())->toBe(55);
});

it('buys weapon for gold vip wallet', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('sword_0');
    $equipment->currency = CurrencyEnum::GOLD;
    $equipment->price = 3;
    $equipment->save();

    $character = characters()->createDraft(7003);
    $character->silver = 0;
    $character->gold = 3;
    $character = placeInCity($character, App\Models\City::KEY_YASEN);

    $buy = shopService()->buyWeapon($character->tg_id, 'sword_0');

    expect($buy->ok)->toBeTrue()
        ->and($buy->character->gold)->toBe(0)
        ->and($buy->character->silver)->toBe(0)
        ->and(backpack()->owns($character->tg_id, 'sword_0'))->toBeTrue();

    $poor = characters()->createDraft(7004);
    $poor->silver = 100;
    $poor->gold = 0;
    $poor = placeInCity($poor, App\Models\City::KEY_YASEN);

    expect(shopService()->buyWeapon($poor->tg_id, 'sword_0')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($poor->tg_id, 'sword_0')->error)->toBe(__('errors.not_enough_gold'));
});

it('rejects buying weapons hidden from shop', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('hammer_0');
    $equipment->enabled = false;
    $equipment->save();

    $character = characters()->createDraft(7005);
    $character->silver = 999;
    $character = placeInCity($character, App\Models\City::KEY_YASEN);

    expect(shopService()->buyWeapon($character->tg_id, 'hammer_0')->ok)->toBeFalse();
});

it('invalidates catalog cache and recovers from corrupt cache payload', function (): void {
    expect(shopCatalog()->findItem('club_0')->weaponDamageMin)->toBe(3)
        ->and(shopCatalog()->findItem('club_0')->weaponDamageMax)->toBe(4);

    $equipment = BackpackCatalog::query()->findOrFail('club_0');
    $equipment->weapon_damage_min = 9;
    $equipment->weapon_damage_max = 11;
    $equipment->save();

    expect(shopCatalog()->findItem('club_0')->weaponDamageMin)->toBe(9)
        ->and(shopCatalog()->findItem('club_0')->weaponDamageMax)->toBe(11);

    Cache::put('backpack.catalog.v1', Collection::make([
        'broken' => new class
        {
            public string $x = 'incomplete-like';
        },
    ]), 3600);

    expect(shopCatalog()->findItem('knife_0')->itemId)->toBe('knife_0');
});

it('seeder is idempotent', function (): void {
    $before = BackpackCatalog::query()->count();
    $this->seed(BackpackCatalogSeeder::class);

    expect(BackpackCatalog::query()->count())->toBe($before);
});
