<?php

declare(strict_types=1);

use App\Actions\Backpack\BackpackDiscardAction;
use App\Actions\Backpack\BackpackDiscardEquippedAction;
use App\Models\Backpack\BackpackItem;
use App\Services\Bag\BagService;

it('discards unequipped gear and destroys socketed gems', function (): void {
    $p = characters()->createDraft(6201);
    $p = grantGemDurability($p, 'ruby_0', 8);

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $socket = socketGem($p, $knife, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knife->refresh();

    expect(app(BagService::class)->socketedGemIds($knife))->toBe(['ruby_0'])
        ->and(bag()->looseGems($p))->toHaveCount(0);

    $discard = app(BackpackDiscardAction::class)->handle($p, $knife->id);

    expect($discard->ok)->toBeTrue()
        ->and(BackpackItem::query()->whereKey($knife->id)->exists())->toBeFalse()
        ->and(bag()->looseGems($discard->character))->toHaveCount(0)
        ->and(hasLooseGem($discard->character, 'ruby_0'))->toBeFalse();
});

it('decrements potion quantity on discard instead of deleting the stack', function (): void {
    $p = characters()->createDraft(6202);
    bag()->addPotion($p->tg_id, bagCatalog()->shopPotionId());
    bag()->addPotion($p->tg_id, bagCatalog()->shopPotionId());
    $potion = bag()->loosePotions($p)->first();

    expect($potion->quantity)->toBe(2);

    $discard = bag()->discardLoose($p, $potion->id);

    expect($discard->ok)->toBeTrue()
        ->and(bag()->loosePotions($discard->character)->first()->quantity)->toBe(1);
});

it('rejects discarding equipped items and missing rows', function (): void {
    $p = characters()->createDraft(6203);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $p = loadout()->equip($p, $knife->id)->character;

    $equipped = backpack()->discard($p, $knife->id);

    expect($equipped->ok)->toBeFalse()
        ->and($equipped->error)->toBe(__('errors.unequip_first'))
        ->and($knife->fresh()->isEquipped())->toBeTrue();

    $missing = backpack()->discard($p, 9_999_999);

    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('errors.item_not_found'));
});

it('discards equipped gear without needing backpack space and destroys gems', function (): void {
    $p = characters()->createDraft(6204);
    $p->backpack_max_rows = 1;
    $p->save();
    $p = grantGemDurability($p, 'ruby_0', 8);

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $socket = socketGem($p, $knife, 'ruby_0');
    expect($socket->ok)->toBeTrue();
    $p = $socket->character;
    $knife->refresh();

    $p = loadout()->equip($p, $knife->id)->character;
    backpack()->addItem($p->tg_id, 'axe_0');

    expect(backpack()->isFull($p))->toBeTrue()
        ->and(bag()->looseGems($p))->toHaveCount(0);

    $discard = app(BackpackDiscardEquippedAction::class)->handle($p, $knife->id);

    expect($discard->ok)->toBeTrue()
        ->and(BackpackItem::query()->whereKey($knife->id)->exists())->toBeFalse()
        ->and(backpack()->rowCount($p->tg_id))->toBe(1)
        ->and(hasLooseGem($discard->character, 'ruby_0'))->toBeFalse();
});

it('rejects discardEquipped for unequipped rows', function (): void {
    $p = characters()->createDraft(6205);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');

    $result = backpack()->discardEquipped($p, $knife->id);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(__('errors.not_equipped'))
        ->and(BackpackItem::query()->whereKey($knife->id)->exists())->toBeTrue();
});
