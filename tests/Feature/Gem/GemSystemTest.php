<?php

declare(strict_types=1);

use App\Models\Backpack\BackpackCatalog;
use App\Models\Bag\BagCatalog;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

it('buys gem into bag and sockets mf into loadout', function (): void {
    $p = characters()->createDraft(8301);
    $p->silver = 100;
    $p = placeInCity($p, App\Models\City::KEY_YASEN);

    $buy = app(BagService::class)->buy($p, 'ruby_0');
    expect($buy->ok)->toBeTrue();
    $p = $buy->character;
    expect(bag()->looseGems($p))->toHaveCount(1)
        ->and(looseGem($p, 'ruby_0')->durability)->toBe(10);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    expect(app(BagService::class)->gemSlotCount($knuckles))->toBe(1);

    $before = app(LoadoutService::class)->forCharacter($p);
    $socket = socketGem($p, $knuckles, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $after = app(LoadoutService::class)->forCharacter($p);
    expect($after->mf->crit)->toBe($before->mf->crit + 4)
        ->and(bag()->looseGems($p))->toHaveCount(0);
});

it('does not allow unsocketing a socketed gem', function (): void {
    $p = characters()->createDraft(8302);
    $p->silver = 50;
    $p->save();
    $p = grantGemDurability($p, 'emerald_0', 7);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'emerald_0');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knuckles->refresh();

    $gem = bag()->socketedInstances($knuckles)->first();
    expect($gem)->not->toBeNull();

    $discard = app(BagService::class)->discardLoose($p, $gem->id);

    expect($discard->ok)->toBeFalse()
        ->and($discard->error)->toBe(__('errors.gem_socketed'))
        ->and(app(BagService::class)->socketedGemIds($knuckles->fresh()))->toBe(['emerald_0'])
        ->and(bag()->looseGems($p->fresh()))->toHaveCount(0);
});

it('destroys socketed gem when durability reaches zero on lose', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8303);
    $p = grantGemDurability($p, 'sapphire_0', 1);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'sapphire_0');
    expect($socket->ok)->toBeTrue();

    $broken = app(BagService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    expect($broken)->toContain('Сапфир ученика')
        ->and(app(BagService::class)->socketedInstances($knuckles))->toHaveCount(0);
});

it('wears socketed gem durability without destroying when above one', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8308);
    $p = grantGemDurability($p, 'ruby_0', 10);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'ruby_0');
    $broken = app(BagService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();
    $instances = app(BagService::class)->socketedInstances($knuckles);

    expect($broken)->toBe([])
        ->and($instances)->toHaveCount(1)
        ->and($instances->first()->catalog_id)->toBe('ruby_0')
        ->and($instances->first()->durability)->toBe(9);
});

it('keeps gem when break roll misses', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.99]));

    $p = characters()->createDraft(8304);
    $p = grantGemDurability($p, 'ruby_0', 10);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'ruby_0');
    $broken = app(BagService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();
    $instances = app(BagService::class)->socketedInstances($knuckles);

    expect($broken)->toBe([])
        ->and($instances)->toHaveCount(1)
        ->and($instances->first()->catalog_id)->toBe('ruby_0')
        ->and($instances->first()->durability)->toBe(10);
});

it('repairs all damaged gear for gold', function (): void {
    $p = characters()->createDraft(8305);
    backpack()->addItem($p->tg_id, 'mobile_0');
    backpack()->addItem($p->tg_id, 'mobile_1');

    $cap = backpack()->findOwned($p->tg_id, 'mobile_0');
    $boots = backpack()->findOwned($p->tg_id, 'mobile_1');
    $cap->durability = 10;
    $cap->save();
    $boots->durability = 10;
    $boots->save();

    $goldCost = repair()->repairAllGoldCost($p);
    expect($goldCost)->toBeGreaterThan(0);

    $p->gold = $goldCost - 1;
    $p->save();
    expect(repair()->repairAll($p)->ok)->toBeFalse();

    $p->gold = $goldCost;
    $p->save();
    $ok = repair()->repairAll($p);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0);

    $cap->refresh();
    $boots->refresh();
    expect($cap->durability)->toBe($cap->max_durability)
        ->and($boots->durability)->toBe($boots->max_durability);
});

it('applies extra durability loss after lose', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8306));
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 5;
    $knuckles->save();

    loadout()->applyFightWearAfterLose($p, 0);
    $knuckles->refresh();

    $loss = BackpackCatalog::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;
    $extra = gameConfig()->settings()['wear']['extraLossOnLose'];
    expect($knuckles->durability)->toBe(5 - $loss - $extra);
});

it('does not socket gems into jewelry', function (): void {
    $p = characters()->createDraft(8307);
    $p = grantGem($p, 'ruby_0', 1);

    backpack()->addItem($p->tg_id, 'focus_0');
    $ring = backpack()->findOwned($p->tg_id, 'focus_0');
    $gem = looseGem($p, 'ruby_0');

    expect(app(BagService::class)->gemSlotCount($ring))->toBe(0);
    expect(app(BagService::class)->socket($p, $ring->id, $gem->id)->ok)->toBeFalse();
});

it('rejects buy and socket for disabled gem', function (): void {
    $p = characters()->createDraft(8309);
    $p->silver = 100;
    $p = placeInCity($p, App\Models\City::KEY_YASEN);
    $p = grantGem($p, 'ruby_0', 1);

    $gem = BagCatalog::query()->findOrFail('ruby_0');
    $gem->enabled = false;
    $gem->save();

    expect(app(BagService::class)->buy($p, 'ruby_0')->ok)->toBeFalse();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $loose = looseGem($p, 'ruby_0');

    expect(app(BagService::class)->socket($p, $knuckles->id, $loose->id)->ok)->toBeFalse();
});

it('discards a loose gem by bag item id', function (): void {
    $p = characters()->createDraft(8311);
    $p = grantGemDurability($p, 'ruby_0', 3);
    $p = grantGemDurability($p, 'emerald_0', 8);
    $p = grantGemDurability($p, 'sapphire_0', 5);

    $emerald = looseGem($p, 'emerald_0');
    $discard = app(BagService::class)->discardLoose($p, $emerald->id);

    expect($discard->ok)->toBeTrue();
    expect(bag()->looseGems($discard->character))->toHaveCount(2)
        ->and(hasLooseGem($discard->character, 'ruby_0'))->toBeTrue()
        ->and(hasLooseGem($discard->character, 'sapphire_0'))->toBeTrue()
        ->and(hasLooseGem($discard->character, 'emerald_0'))->toBeFalse();
});

it('rejects discarding a missing bag item', function (): void {
    $p = characters()->createDraft(8312);
    $p = grantGem($p, 'ruby_0', 1);

    $discard = app(BagService::class)->discardLoose($p, 9_999_999);

    expect($discard->ok)->toBeFalse()
        ->and($discard->error)->toBe(__('errors.item_not_found'))
        ->and(bag()->looseGems($p->fresh()))->toHaveCount(1);
});

it('sockets a specific bag gem when bag has duplicate catalog ids', function (): void {
    $p = characters()->createDraft(8310);
    $p = grantGemDurability($p, 'ruby_0', 3);
    $p = grantGemDurability($p, 'ruby_0', 10);

    $first = bag()->looseGems($p)->first();
    expect($first->durability)->toBe(3);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = bag()->socket($p, $knuckles->id, $first->id);
    expect($socket->ok)->toBeTrue();

    $knuckles->refresh();
    $loose = bag()->looseGems($socket->character);
    $socketed = bag()->socketedInstances($knuckles);

    expect($socketed->first()->durability)->toBe(3)
        ->and($loose)->toHaveCount(1)
        ->and($loose->first()->durability)->toBe(10);
});
