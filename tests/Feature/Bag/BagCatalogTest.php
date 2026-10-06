<?php

declare(strict_types=1);

use App\Enums\Bag\BagKindEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\BagCatalog;
use App\Services\Bag\BagCatalog as BagCatalogService;
use Database\Seeders\BagCatalogSeeder;
use Illuminate\Support\Facades\Cache;

it('seeds gem catalog into the database', function (): void {
    $catalog = app(BagCatalogService::class);

    expect(BagCatalog::query()->where('kind', BagKindEnum::GEM)->count())->toBe(4)
        ->and($catalog->findGem('ruby_0')->name)->toBe('Рубин ученика')
        ->and($catalog->findGem('ruby_0')->type)->toBe(GemTypeEnum::RUBY)
        ->and($catalog->findGem('ruby_0')->mf->crit)->toBe(4)
        ->and($catalog->findGem('ruby_0')->maxDurability)->toBe(10)
        ->and($catalog->findGem('diamond_0')->inShop)->toBeFalse()
        ->and($catalog->findGem('diamond_0')->mf->antiDodge)->toBe(4)
        ->and($catalog->breakChanceOnLose())->toBe(40);
});

it('lists only enabled shop gems', function (): void {
    $catalog = app(BagCatalogService::class);
    $ids = [];

    foreach ($catalog->shopGems() as $gem) {
        $ids[] = $gem->id;
    }

    expect($ids)->toBe(['ruby_0', 'emerald_0', 'sapphire_0']);

    $ruby = BagCatalog::query()->findOrFail('ruby_0');
    $ruby->enabled = false;
    $ruby->save();

    $ids = [];

    foreach (app(BagCatalogService::class)->shopGems() as $gem) {
        $ids[] = $gem->id;
    }

    expect($ids)->not->toContain('ruby_0');
});

it('finds trashed gems for already owned lookup', function (): void {
    $gem = BagCatalog::query()->findOrFail('emerald_0');
    $gem->delete();

    $def = app(BagCatalogService::class)->findGem('emerald_0');

    expect($def->id)->toBe('emerald_0')
        ->and($def->name)->toBe('Изумруд ученика')
        ->and(app(BagCatalogService::class)->hasGem('emerald_0'))->toBeTrue();
});

it('invalidates catalog cache on save', function (): void {
    Cache::flush();

    $catalog = app(BagCatalogService::class);
    expect($catalog->findGem('ruby_0')->name)->toBe('Рубин ученика');

    $gem = BagCatalog::query()->findOrFail('ruby_0');
    $gem->name = 'Рубин героя';
    $gem->save();

    expect(app(BagCatalogService::class)->findGem('ruby_0')->name)->toBe('Рубин героя');
});

it('blocks force delete when gem is referenced in bag or socket', function (): void {
    $p = characters()->createDraft(7101);
    $p = grantGem($p, 'ruby_0', 1);

    $gem = BagCatalog::query()->findOrFail('ruby_0');
    expect($gem->isReferenced())->toBeTrue();

    bag()->discardLoose($p, looseGem($p, 'ruby_0')->id);
    expect($gem->fresh()->isReferenced())->toBeFalse();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $p = grantGem($p, 'sapphire_0', 1);
    socketGem($p, $knuckles, 'sapphire_0');

    expect(BagCatalog::query()->findOrFail('sapphire_0')->isReferenced())->toBeTrue();
});

it('keeps only type mf field on save', function (): void {
    $gem = BagCatalog::factory()->create([
        'catalog_id' => 'ruby_99',
        'type' => GemTypeEnum::RUBY,
        'mf_crit' => 8,
        'mf_dodge' => 3,
        'mf_anti_dodge' => 2,
        'mf_anti_crit' => 1,
    ]);

    expect($gem->fresh()->mf_crit)->toBe(8)
        ->and($gem->fresh()->mf_dodge)->toBe(0)
        ->and($gem->fresh()->mf_anti_dodge)->toBe(0)
        ->and($gem->fresh()->mf_anti_crit)->toBe(0);
});

it('is idempotent when reseeding', function (): void {
    $this->seed(BagCatalogSeeder::class);
    $this->seed(BagCatalogSeeder::class);

    expect(BagCatalog::query()->where('kind', BagKindEnum::GEM)->count())->toBe(4)
        ->and(BagCatalog::query()->where('kind', BagKindEnum::POTION)->count())->toBe(2);
});
