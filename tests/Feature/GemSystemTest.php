<?php

declare(strict_types=1);

use App\Models\Equipment;
use App\Services\Inventory\GemService;
use App\Services\Inventory\LoadoutService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

it('buys gem into pouch and sockets mf into loadout', function (): void {
    $p = characters()->createDraft(8301);
    $p->silver = 100;
    $p->save();

    $buy = app(GemService::class)->buy($p, 'gem_ruby_t1');
    expect($buy->ok)->toBeTrue();
    $p = $buy->character;
    expect(app(GemService::class)->pouch($p))->toHaveKey('gem_ruby_t1');

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    expect(app(GemService::class)->gemSlotCount($knuckles))->toBe(1);

    $before = app(LoadoutService::class)->forCharacter($p);
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_ruby_t1');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $after = app(LoadoutService::class)->forCharacter($p);
    expect($after->mf->crit)->toBe($before->mf->crit + 4)
        ->and(app(GemService::class)->pouch($p))->not->toHaveKey('gem_ruby_t1');
});

it('unsockets gem back to pouch for silver', function (): void {
    $p = characters()->createDraft(8302);
    $p->silver = 50;
    $p->gem_pouch = ['gem_emerald_t1' => 1];
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_emerald_t1');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $unsocket = app(GemService::class)->unsocket($p, $knuckles->id, 0);
    expect($unsocket->ok)->toBeTrue();
    $p = $unsocket->character;
    $knuckles->refresh();

    expect(app(GemService::class)->socketedGemIds($knuckles))->toBe([])
        ->and(app(GemService::class)->pouch($p)['gem_emerald_t1'])->toBe(1)
        ->and($p->silver)->toBe(45);
});

it('breaks socketed gems on lose by chance', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8303);
    $p->gem_pouch = ['gem_sapphire_t1' => 1];
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_sapphire_t1');
    expect($socket->ok)->toBeTrue();

    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    expect($broken)->toContain('Сапфир новичка')
        ->and(app(GemService::class)->socketedGemIds($knuckles))->toBe([]);
});

it('keeps gem when break roll misses', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.99]));

    $p = characters()->createDraft(8304);
    $p->gem_pouch = ['gem_ruby_t1' => 1];
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_ruby_t1');
    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    expect($broken)->toBe([])
        ->and(app(GemService::class)->socketedGemIds($knuckles))->toBe(['gem_ruby_t1']);
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
    $p->gem_pouch = ['gem_ruby_t1' => 1];
    $p->save();

    inventory()->addItem($p->tg_id, 'focus_0');
    $ring = inventory()->findOwned($p->tg_id, 'focus_0');

    expect(app(GemService::class)->gemSlotCount($ring))->toBe(0);
    expect(app(GemService::class)->socket($p, $ring->id, 'gem_ruby_t1')->ok)->toBeFalse();
});
