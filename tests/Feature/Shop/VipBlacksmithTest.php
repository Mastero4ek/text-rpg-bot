<?php

declare(strict_types=1);

use App\Enums\Equipment\RepairEnum;
use App\Models\Backpack\BackpackCatalog;

it('keeps ordinary smith from repairing vip-tier gear', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('knife_0');
    $equipment->repair_tier = RepairEnum::VIP;
    $equipment->save();

    $p = characters()->createDraft(8401);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 10;
    $knife->save();

    expect(BackpackCatalog::query()->findOrFail('knife_0')->repair_tier)->toBe(RepairEnum::VIP)
        ->and(repair()->damagedList($p->tg_id))->toHaveCount(0)
        ->and(repair()->damagedVipList($p->tg_id))->toHaveCount(1);

    $p->silver = 10_000;
    $p->save();

    expect(repair()->repair($p, $knife->id)->ok)->toBeFalse()
        ->and(repair()->repair($p, $knife->id)->error)->toBe(__('errors.needs_vip_smith'));
});

it('repairs vip gear with gold pass when no premium', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('axe_0');
    $equipment->repair_tier = RepairEnum::VIP;
    $equipment->save();

    $p = characters()->createDraft(8402);
    backpack()->addItem($p->tg_id, 'axe_0');
    $axe = backpack()->findOwned($p->tg_id, 'axe_0');
    $axe->durability = $axe->max_durability - 4;
    $axe->save();

    $silver = repair()->repairVipSilverCost($axe);
    $goldPass = repair()->repairVipGoldPass();

    $p->silver = $silver;
    $p->gold = $goldPass - 1;
    $p->save();
    expect(repair()->repairVip($p, $axe->id)->ok)->toBeFalse();

    $p->gold = $goldPass;
    $p->save();
    $ok = repair()->repairVip($p, $axe->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0)
        ->and($ok->character->silver)->toBe(0);

    $axe->refresh();
    expect($axe->durability)->toBe($axe->max_durability);
});

it('skips gold pass for premium on vip repair', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('club_0');
    $equipment->repair_tier = RepairEnum::VIP;
    $equipment->save();

    $p = characters()->createDraft(8403);
    $p->premium_until = now()->addDay();
    backpack()->addItem($p->tg_id, 'club_0');
    $club = backpack()->findOwned($p->tg_id, 'club_0');
    $club->durability = $club->max_durability - 3;
    $club->save();

    $silver = repair()->repairVipSilverCost($club);
    $p->silver = $silver;
    $p->gold = 0;
    $p->save();

    $ok = repair()->repairVip($p, $club->id);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->gold)->toBe(0)
        ->and($ok->character->silver)->toBe(0);
});

it('charges vip silver multiplier over base repair cost', function (): void {
    $equipment = BackpackCatalog::query()->findOrFail('knife_0');
    $equipment->repair_tier = RepairEnum::VIP;
    $equipment->save();

    $p = characters()->createDraft(8404);
    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = $knife->max_durability - 5;
    $knife->save();

    $base = repair()->repairCost($knife);
    $mult = gameConfig()->settings()['vipRepair']['silverMultiplier'];

    expect(repair()->repairVipSilverCost($knife))->toBe($base * $mult);
});
