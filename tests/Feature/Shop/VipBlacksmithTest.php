<?php

declare(strict_types=1);

use App\Enums\Equipment\RepairEnum;
use App\Models\Equipment;

it('keeps ordinary smith from repairing vip-tier gear', function (): void {
    $p = characters()->createDraft(8401);
    inventory()->addItem($p->tg_id, 'knife_3');
    $knife = inventory()->findOwned($p->tg_id, 'knife_3');
    $knife->durability = 10;
    $knife->save();

    expect(Equipment::query()->findOrFail('knife_3')->repair_tier)->toBe(RepairEnum::VIP)
        ->and(inventory()->damagedList($p->tg_id))->toHaveCount(0)
        ->and(inventory()->damagedVipList($p->tg_id))->toHaveCount(1);

    $p->silver = 10_000;
    $p->save();

    expect(inventory()->repair($p, $knife->id)->ok)->toBeFalse()
        ->and(inventory()->repair($p, $knife->id)->error)->toBe(__('errors.needs_vip_smith'));
});

it('repairs vip gear with gold pass when no premium', function (): void {
    $p = characters()->createDraft(8402);
    inventory()->addItem($p->tg_id, 'axe_3');
    $axe = inventory()->findOwned($p->tg_id, 'axe_3');
    $axe->durability = $axe->max_durability - 4;
    $axe->save();

    $silver = inventory()->repairVipSilverCost($axe);
    $goldPass = inventory()->repairVipGoldPass();

    $p->silver = $silver;
    $p->gold = $goldPass - 1;
    $p->save();
    expect(inventory()->repairVip($p, $axe->id)->ok)->toBeFalse();

    $p->gold = $goldPass;
    $p->save();
    $ok = inventory()->repairVip($p, $axe->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0)
        ->and($ok->character->silver)->toBe(0);

    $axe->refresh();
    expect($axe->durability)->toBe($axe->max_durability);
});

it('skips gold pass for premium on vip repair', function (): void {
    $p = characters()->createDraft(8403);
    $p->premium_until = now()->addDay();
    inventory()->addItem($p->tg_id, 'club_3');
    $club = inventory()->findOwned($p->tg_id, 'club_3');
    $club->durability = $club->max_durability - 3;
    $club->save();

    $silver = inventory()->repairVipSilverCost($club);
    $p->silver = $silver;
    $p->gold = 0;
    $p->save();

    $ok = inventory()->repairVip($p, $club->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0)
        ->and($ok->character->silver)->toBe(0);
});

it('charges vip silver multiplier over base repair cost', function (): void {
    $p = characters()->createDraft(8404);
    inventory()->addItem($p->tg_id, 'knife_3');
    $knife = inventory()->findOwned($p->tg_id, 'knife_3');
    $knife->durability = $knife->max_durability - 5;
    $knife->save();

    $base = inventory()->repairCost($knife);
    $mult = gameConfig()->settings()['vipRepair']['silverMultiplier'];

    expect(inventory()->repairVipSilverCost($knife))->toBe($base * $mult);
});
