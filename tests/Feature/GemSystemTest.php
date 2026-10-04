<?php

declare(strict_types=1);

use App\Models\Equipment;
use App\Models\Gem;
use App\Services\Gem\GemService;
use App\Services\Inventory\LoadoutService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

it('buys gem into pouch and sockets mf into loadout', function (): void {
    $p = characters()->createDraft(8301);
    $p->silver = 100;
    $p->save();

    $buy = app(GemService::class)->buy($p, 'ruby_0');
    expect($buy->ok)->toBeTrue();
    $p = $buy->character;
    $pouch = app(GemService::class)->pouch($p);
    expect($pouch)->toHaveCount(1)
        ->and($pouch[0]['gem_id'])->toBe('ruby_0')
        ->and($pouch[0]['durability'])->toBe(10);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    expect(app(GemService::class)->gemSlotCount($knuckles))->toBe(1);

    $before = app(LoadoutService::class)->forCharacter($p);
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $after = app(LoadoutService::class)->forCharacter($p);
    expect($after->mf->crit)->toBe($before->mf->crit + 4)
        ->and(app(GemService::class)->pouch($p))->toBe([]);
});

it('unsockets gem back to pouch for silver and keeps durability', function (): void {
    $p = characters()->createDraft(8302);
    $p->silver = 50;
    $p->gem_pouch = gemPouch('emerald_0', 7);
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $unsocket = app(GemService::class)->unsocket($p, $knuckles->id, 0);
    expect($unsocket->ok)->toBeTrue();
    $p = $unsocket->character;
    $knuckles->refresh();
    $pouch = app(GemService::class)->pouch($p);

    expect(app(GemService::class)->socketedGemIds($knuckles))->toBe([])
        ->and($pouch)->toHaveCount(1)
        ->and($pouch[0]['gem_id'])->toBe('emerald_0')
        ->and($pouch[0]['durability'])->toBe(7)
        ->and($p->silver)->toBe(45);
});

it('destroys socketed gem when durability reaches zero on lose', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8303);
    $p->gem_pouch = gemPouch('sapphire_0', 1);
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();

    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    expect($broken)->toContain('Сапфир новичка')
        ->and(app(GemService::class)->socketedInstances($knuckles))->toBe([]);
});

it('wears socketed gem durability without destroying when above one', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8308);
    $p->gem_pouch = gemPouch('ruby_0', 10);
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();
    $instances = app(GemService::class)->socketedInstances($knuckles);

    expect($broken)->toBe([])
        ->and($instances)->toHaveCount(1)
        ->and($instances[0]['gem_id'])->toBe('ruby_0')
        ->and($instances[0]['durability'])->toBe(9);
});

it('keeps gem when break roll misses', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.99]));

    $p = characters()->createDraft(8304);
    $p->gem_pouch = gemPouch('ruby_0', 10);
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();
    $instances = app(GemService::class)->socketedInstances($knuckles);

    expect($broken)->toBe([])
        ->and($instances)->toHaveCount(1)
        ->and($instances[0]['gem_id'])->toBe('ruby_0')
        ->and($instances[0]['durability'])->toBe(10);
});

it('repairs all damaged gear for gold', function (): void {
    $p = characters()->createDraft(8305);
    inventory()->addItem($p->tg_id, 'mobile_0');
    inventory()->addItem($p->tg_id, 'mobile_1');

    $cap = inventory()->findOwned($p->tg_id, 'mobile_0');
    $boots = inventory()->findOwned($p->tg_id, 'mobile_1');
    $cap->durability = 10;
    $cap->save();
    $boots->durability = 10;
    $boots->save();

    $goldCost = inventory()->repairAllGoldCost($p);
    expect($goldCost)->toBeGreaterThan(0);

    $p->gold = $goldCost - 1;
    $p->save();
    expect(inventory()->repairAll($p)->ok)->toBeFalse();

    $p->gold = $goldCost;
    $p->save();
    $ok = inventory()->repairAll($p);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0);

    $cap->refresh();
    $boots->refresh();
    expect($cap->durability)->toBe($cap->max_durability)
        ->and($boots->durability)->toBe($boots->max_durability);
});

it('applies extra durability loss after lose', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8306));
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 5;
    $knuckles->save();

    inventory()->applyFightWearAfterLose($p, 0);
    $knuckles->refresh();

    $loss = Equipment::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;
    $extra = gameConfig()->settings()['wear']['extraLossOnLose'];
    expect($knuckles->durability)->toBe(5 - $loss - $extra);
});

it('does not socket gems into jewelry', function (): void {
    $p = characters()->createDraft(8307);
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    inventory()->addItem($p->tg_id, 'focus_0');
    $ring = inventory()->findOwned($p->tg_id, 'focus_0');

    expect(app(GemService::class)->gemSlotCount($ring))->toBe(0);
    expect(app(GemService::class)->socket($p, $ring->id, 0)->ok)->toBeFalse();
});

it('rejects buy and socket for disabled gem', function (): void {
    $gem = Gem::query()->findOrFail('ruby_0');
    $gem->enabled = false;
    $gem->save();

    $p = characters()->createDraft(8309);
    $p->silver = 100;
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    expect(app(GemService::class)->buy($p, 'ruby_0')->ok)->toBeFalse();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    expect(app(GemService::class)->socket($p, $knuckles->id, 0)->ok)->toBeFalse();
});

it('sockets by pouch index when pouch has duplicate gem ids', function (): void {
    $p = characters()->createDraft(8310);
    $p->gem_pouch = [
        ['gem_id' => 'ruby_0', 'durability' => 3],
        ['gem_id' => 'ruby_0', 'durability' => 10],
    ];
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();

    $knuckles->refresh();
    $pouch = app(GemService::class)->pouch($socket->character);
    $socketed = app(GemService::class)->socketedInstances($knuckles);

    expect($socketed[0]['durability'])->toBe(3)
        ->and($pouch)->toHaveCount(1)
        ->and($pouch[0]['durability'])->toBe(10);
});
