<?php

declare(strict_types=1);

use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\SlotEnum;
use App\Models\Bag\BagCatalog;
use App\Models\Bag\BagItem;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Enemy\EnemyDrop;

it('grants bag item at 100 percent from snapshot', function (): void {
    $p = characters()->createDraft(7301);
    $catalog = EnemyCatalog::factory()->create();
    EnemyDrop::factory()->create([
        'enemy_catalog_id' => $catalog->catalog_id,
        'bag_catalog_id' => 'heal_0',
        'chance_pct' => 100,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog->fresh(), $p);
    EnemyDrop::query()->where('enemy_catalog_id', $catalog->catalog_id)->delete();

    app(EnemyApplyWinLootAction::class)->handle($p, $enemy);

    expect(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(1);
});

it('skips drop at 0 percent', function (): void {
    $p = characters()->createDraft(7302);
    $catalog = EnemyCatalog::factory()->create();
    EnemyDrop::factory()->create([
        'enemy_catalog_id' => $catalog->catalog_id,
        'bag_catalog_id' => 'heal_0',
        'chance_pct' => 0,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog->fresh(), $p);

    app(EnemyApplyWinLootAction::class)->handle($p, $enemy);

    expect(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(0);
});

it('skips drop when bag is full', function (): void {
    $p = characters()->createDraft(7303);
    $p->bag_max_rows = 1;
    $p->save();
    grantGem($p, 'ruby_0', 1);

    $catalog = EnemyCatalog::factory()->create();
    EnemyDrop::factory()->create([
        'enemy_catalog_id' => $catalog->catalog_id,
        'bag_catalog_id' => 'heal_0',
        'chance_pct' => 100,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog->fresh(), $p);

    app(EnemyApplyWinLootAction::class)->handle($p, $enemy);

    expect(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(0)
        ->and(BagItem::query()->where('tg_id', $p->tg_id)->where('kind', BagKindEnum::GEM->value)->count())->toBe(1);
});

it('rolls several drop rows independently', function (): void {
    $p = characters()->createDraft(7304);
    $catalog = EnemyCatalog::factory()->create();
    EnemyDrop::factory()->create([
        'enemy_catalog_id' => $catalog->catalog_id,
        'bag_catalog_id' => 'heal_0',
        'chance_pct' => 100,
    ]);
    EnemyDrop::factory()->create([
        'enemy_catalog_id' => $catalog->catalog_id,
        'bag_catalog_id' => 'stamina_0',
        'chance_pct' => 0,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog->fresh(), $p);

    app(EnemyApplyWinLootAction::class)->handle($p, $enemy);

    expect(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::HEAL))->toBe(1)
        ->and(bag()->potionCountByProfile($p->tg_id, App\Enums\Equipment\ProfileEnum::STAMINA))->toBe(0);
});

it('does not drop mirrored player gear', function (): void {
    $p = characters()->createDraft(7305);
    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'mobile_0', SlotEnum::HELMET);
    $catalog = EnemyCatalog::query()->findOrFail('chance_wanderer');
    $enemy = enemies()->makeFromCatalog($catalog, $p);

    foreach ($enemy->drops as $drop) {
        $item = BagCatalog::query()->find($drop['bag_catalog_id']);
        expect($item)->not->toBeNull()
            ->and($item->kind)->not->toBe(App\Enums\Equipment\TypeEnum::ARMOR);
    }

    expect($enemy->drops)->not->toContain(['bag_catalog_id' => 'mobile_0']);
});
