<?php

declare(strict_types=1);

use App\Actions\Character\CharacterSetBagMaxRowsAction;
use App\Actions\Inventory\InventoryDiscardAction;
use App\Models\Inventory;
use App\Services\Gem\GemService;

it('seeds bag_max_rows from settings on createDraft', function (): void {
    $p = characters()->createDraft(8401);
    $gems = app(GemService::class);

    expect($p->bag_max_rows)->toBe(10)
        ->and($gems->bagMaxRows($p))->toBe(10)
        ->and($gems->bagRowCount($p))->toBe(0)
        ->and($gems->isBagFull($p))->toBeFalse()
        ->and($gems->canAcceptBagRows($p, 10))->toBeTrue()
        ->and($gems->canAcceptBagRows($p, 11))->toBeFalse();
});

it('blocks buy when bag is full', function (): void {
    $p = characters()->createDraft(8402);
    $p->bag_max_rows = 1;
    $p->silver = 999;
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    $gems = app(GemService::class);

    expect($gems->isBagFull($p))->toBeTrue()
        ->and($gems->canAcceptBagRows($p, 1))->toBeFalse();

    $buy = $gems->buy($p, 'emerald_0');

    expect($buy->ok)->toBeFalse()
        ->and($buy->error)->toBe(__('errors.bag_full'))
        ->and($gems->pouch($p->fresh()))->toHaveCount(1);
});

it('blocks grant when bag cannot accept qty', function (): void {
    $p = characters()->createDraft(8403);
    $p->bag_max_rows = 2;
    $p->gem_pouch = gemPouch('ruby_0', qty: 2);
    $p->save();

    expect(fn () => app(GemService::class)->grantToPouch($p, 'emerald_0', 1))
        ->toThrow(RuntimeException::class, __('errors.bag_full'));
});

it('blocks unsocket when bag is full', function (): void {
    $p = characters()->createDraft(8404);
    $p->bag_max_rows = 1;
    $p->silver = 50;
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 0);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;

    $p->gem_pouch = gemPouch('emerald_0');
    $p->save();

    $unsocket = app(GemService::class)->unsocket($p, $knuckles->id, 0);

    expect($unsocket->ok)->toBeFalse()
        ->and($unsocket->error)->toBe(__('errors.bag_full'))
        ->and(app(GemService::class)->socketedGemIds($knuckles->fresh()))->toBe(['ruby_0']);
});

it('blocks discard of socketed gear when bag cannot fit gems', function (): void {
    $p = characters()->createDraft(8405);
    $p->bag_max_rows = 1;
    $p->gem_pouch = gemPouch('ruby_0');
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $socket = app(GemService::class)->socket($p, $knife->id, 0);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knife->refresh();

    $p->gem_pouch = gemPouch('emerald_0');
    $p->save();

    $discard = app(InventoryDiscardAction::class)->handle($p, $knife->id);

    expect($discard->ok)->toBeFalse()
        ->and($discard->error)->toBe(__('errors.bag_full'))
        ->and(Inventory::query()->whereKey($knife->id)->exists())->toBeTrue()
        ->and(app(GemService::class)->socketedGemIds($knife->fresh()))->toBe(['ruby_0']);
});

it('updates bag_max_rows through action and rejects values below one', function (): void {
    $p = characters()->createDraft(8406);
    $gems = app(GemService::class);

    $updated = app(CharacterSetBagMaxRowsAction::class)->handle($p, 3);

    expect($updated->bag_max_rows)->toBe(3)
        ->and($gems->bagMaxRows($updated))->toBe(3);

    expect(fn () => app(CharacterSetBagMaxRowsAction::class)->handle($updated, 0))
        ->toThrow(InvalidArgumentException::class);
});
