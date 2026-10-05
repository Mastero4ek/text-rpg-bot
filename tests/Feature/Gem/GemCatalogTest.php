<?php

declare(strict_types=1);

use App\Enums\Gem\GemTypeEnum;
use App\Models\Gem;
use App\Services\Gem\GemCatalog;
use Database\Seeders\GemSeeder;
use Illuminate\Support\Facades\Cache;

it('seeds gem catalog into the database', function (): void {
    $catalog = app(GemCatalog::class);

    expect(Gem::query()->count())->toBe(4)
        ->and($catalog->find('ruby_0')->name)->toBe('Рубин ученика')
        ->and($catalog->find('ruby_0')->type)->toBe(GemTypeEnum::RUBY)
        ->and($catalog->find('ruby_0')->mf->crit)->toBe(4)
        ->and($catalog->find('ruby_0')->maxDurability)->toBe(10)
        ->and($catalog->find('diamond_0')->inShop)->toBeFalse()
        ->and($catalog->find('diamond_0')->mf->antiDodge)->toBe(4)
        ->and($catalog->breakChanceOnLose())->toBe(40)
        ->and($catalog->unsocketSilver())->toBe(5)
        ->and($catalog->wardGold())->toBe(2);
});

it('lists only enabled shop gems', function (): void {
    $catalog = app(GemCatalog::class);
    $ids = [];

    foreach ($catalog->shopGems() as $gem) {
        $ids[] = $gem->id;
    }

    expect($ids)->toBe(['ruby_0', 'emerald_0', 'sapphire_0']);

    $ruby = Gem::query()->findOrFail('ruby_0');
    $ruby->enabled = false;
    $ruby->save();

    $ids = [];

    foreach (app(GemCatalog::class)->shopGems() as $gem) {
        $ids[] = $gem->id;
    }

    expect($ids)->not->toContain('ruby_0');
});

it('finds trashed gems for already owned lookup', function (): void {
    $gem = Gem::query()->findOrFail('emerald_0');
    $gem->delete();

    $def = app(GemCatalog::class)->find('emerald_0');

    expect($def->id)->toBe('emerald_0')
        ->and($def->name)->toBe('Изумруд ученика')
        ->and(app(GemCatalog::class)->has('emerald_0'))->toBeTrue();
});

it('invalidates catalog cache on save', function (): void {
    Cache::flush();

    $catalog = app(GemCatalog::class);
    expect($catalog->find('ruby_0')->name)->toBe('Рубин ученика');

    $gem = Gem::query()->findOrFail('ruby_0');
    $gem->name = 'Рубин героя';
    $gem->save();

    expect(app(GemCatalog::class)->find('ruby_0')->name)->toBe('Рубин героя');
});

it('blocks force delete when gem is referenced in pouch or socket', function (): void {
    $p = characters()->createDraft(7101);
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    $gem = Gem::query()->findOrFail('ruby_0');
    expect($gem->isReferenced())->toBeTrue();

    $p->gem_pouch = [];
    $p->save();
    expect($gem->fresh()->isReferenced())->toBeFalse();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $p->gem_pouch = gemPouch('sapphire_0');
    $p->save();
    app(App\Services\Gem\GemService::class)->socket($p, $knuckles->id, 0);

    expect(Gem::query()->findOrFail('sapphire_0')->isReferenced())->toBeTrue();
});

it('keeps only type mf field on save', function (): void {
    $gem = Gem::factory()->create([
        'gem_id' => 'ruby_99',
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
    $this->seed(GemSeeder::class);
    $this->seed(GemSeeder::class);

    expect(Gem::query()->count())->toBe(4);
});
