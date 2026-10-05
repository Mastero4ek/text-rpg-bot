<?php

declare(strict_types=1);

use App\Actions\Inventory\InventoryDiscardAction;
use App\Models\Inventory;
use App\Services\Gem\GemService;

it('discards unequipped gear and moves socketed gems to pouch', function (): void {
    $p = characters()->createDraft(6201);
    $p->gem_pouch = gemPouch('ruby_0', 8);
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $socket = app(GemService::class)->socket($p, $knife->id, 0);
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knife->refresh();

    expect(app(GemService::class)->socketedGemIds($knife))->toBe(['ruby_0'])
        ->and(app(GemService::class)->pouch($p))->toBe([]);

    $discard = app(InventoryDiscardAction::class)->handle($p, $knife->id);

    expect($discard->ok)->toBeTrue()
        ->and(Inventory::query()->whereKey($knife->id)->exists())->toBeFalse();

    $pouch = app(GemService::class)->pouch($discard->character);

    expect($pouch)->toHaveCount(1)
        ->and($pouch[0]['gem_id'])->toBe('ruby_0')
        ->and($pouch[0]['durability'])->toBe(8);
});

it('decrements potion quantity on discard instead of deleting the stack', function (): void {
    $p = characters()->createDraft(6202);
    inventory()->addItem($p->tg_id, shopCatalog()->shopPotionId());
    inventory()->addItem($p->tg_id, shopCatalog()->shopPotionId());
    $potion = inventory()->findOwned($p->tg_id, shopCatalog()->shopPotionId());

    expect($potion->quantity)->toBe(2);

    $discard = inventory()->discard($p, $potion->id);

    expect($discard->ok)->toBeTrue()
        ->and(inventory()->findOwned($p->tg_id, shopCatalog()->shopPotionId())->quantity)->toBe(1);
});

it('rejects discarding equipped items and missing rows', function (): void {
    $p = characters()->createDraft(6203);
    inventory()->addItem($p->tg_id, 'knife_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    $equipped = inventory()->discard($p, $knife->id);

    expect($equipped->ok)->toBeFalse()
        ->and($equipped->error)->toBe(__('errors.unequip_first'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();

    $missing = inventory()->discard($p, 9_999_999);

    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('errors.item_not_found'));
});
