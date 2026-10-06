<?php

declare(strict_types=1);

use App\Services\Bag\BagService;

it('blocks buy when bag is full', function (): void {
    $p = characters()->createDraft(8401);
    $p->bag_max_rows = 1;
    $p->silver = 999;
    $p->save();
    $p = grantGem($p, 'ruby_0', 1);

    expect(app(BagService::class)->isBagFull($p))->toBeTrue();

    $buy = app(BagService::class)->buy($p, 'emerald_0');

    expect($buy->ok)->toBeFalse()
        ->and($buy->error)->toBe(__('errors.bag_full'));
});

it('blocks grant when bag cannot accept qty', function (): void {
    $p = characters()->createDraft(8402);
    $p->bag_max_rows = 2;
    $p->save();
    $p = grantGem($p, 'ruby_0', 2);

    expect(fn (): App\Models\Character => grantGem($p, 'emerald_0', 1))
        ->toThrow(RuntimeException::class, __('errors.bag_full'));
});

it('destroys socketed gems on gear discard without needing bag space', function (): void {
    $p = characters()->createDraft(8403);
    $p->bag_max_rows = 1;
    $p->save();
    $p = grantGem($p, 'ruby_0', 1);

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $socket = socketGem($p, $knife, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $p = grantGem($p, 'emerald_0', 1);
    expect(app(BagService::class)->isBagFull($p))->toBeTrue();

    $discard = backpack()->discard($p, $knife->id);

    expect($discard->ok)->toBeTrue()
        ->and(hasLooseGem($discard->character, 'ruby_0'))->toBeFalse()
        ->and(hasLooseGem($discard->character, 'emerald_0'))->toBeTrue()
        ->and(app(BagService::class)->bagRowCount($discard->character))->toBe(1);
});
