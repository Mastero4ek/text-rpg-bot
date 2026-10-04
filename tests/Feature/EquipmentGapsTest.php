<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Enums\StanceEnum;
use App\Models\Equipment;
use App\Services\Inventory\LoadoutService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\MenuHandler;
use Illuminate\Support\Facades\Http;

it('renders gear text with damage range broken slot and totals', function (): void {
    $p = characters()->createDraft(8701);
    $p->level = 1;
    $p->agility = 2;
    $p->instinct = 1;
    $p->username = 'GearUi';
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_1');
    inventory()->addItem($p->tg_id, 'mobile_0');
    $knife = inventory()->findOwned($p->tg_id, 'knife_1');
    $helm = inventory()->findOwned($p->tg_id, 'mobile_0');

    expect(inventory()->equip($p, $knife->id)->ok)->toBeTrue();
    $p = characters()->findByTgId($p->tg_id);
    expect(inventory()->equip($p, $helm->id)->ok)->toBeTrue();
    $p = characters()->findByTgId($p->tg_id);

    $knife->durability = 0;
    $knife->save();

    $loadout = app(LoadoutService::class)->forCharacter($p);
    $text = app(LoadoutService::class)->gearText($loadout);
    $knifeDef = shopCatalog()->findItem('knife_1');
    $helmDef = shopCatalog()->findItem('mobile_0');

    expect($text)->toContain(__('profile.gear_slot_broken', [
        'slot' => SlotEnum::RIGHT_HAND->getLabel(),
        'name' => $knife->item_name,
    ]))
        ->and($text)->toContain($helm->item_name)
        ->and($text)->toContain('+' . $helmDef->statBonus . ' HP')
        ->and($text)->toContain(__('profile.gear_slot_empty', [
            'slot' => SlotEnum::LEFT_HAND->getLabel(),
        ]))
        ->and($text)->toContain('урон 0')
        ->and($text)->not->toContain($knifeDef->weaponDamageMin . '–' . $knifeDef->weaponDamageMax)
        ->and($text)->toContain('голова ' . $helmDef->armor);
});

it('builds item card with damage range durability and empty gem socket', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $p = characters()->createDraft(8702);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->level = 1;
    $p->agility = 2;
    $p->instinct = 1;
    $p->username = 'CardUi';
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_1');
    $knife = inventory()->findOwned($p->tg_id, 'knife_1');
    $def = shopCatalog()->findItem('knife_1');

    $update = new TelegramUpdate([
        'update_id' => 9701,
        'callback_query' => [
            'id' => 'cb-card-1',
            'data' => 'inv:card:' . $knife->id,
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 41,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'inv',
            ],
        ],
    ]);

    app(MenuHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $expectedBase = app(LoadoutService::class)->itemCardText($def);
    $expectedDurability = __('profile.item_card_durability', [
        'current' => $knife->durability,
        'max' => $knife->max_durability,
    ]);
    $expectedGems = __('profile.item_card_gems', [
        'value' => __('smith.gem_slot_empty'),
    ]);

    Http::assertSent(function ($request) use ($expectedBase, $expectedDurability, $expectedGems, $def): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $data = $request->data();
        $text = '';

        if (array_key_exists('text', $data) && is_string($data['text'])) {
            $text = $data['text'];
        }

        return str_contains($text, $expectedBase)
            && str_contains($text, $expectedDurability)
            && str_contains($text, $expectedGems)
            && str_contains($text, 'Урон: ' . $def->weaponDamageMin . '–' . $def->weaponDamageMax);
    });
});

it('rejects repair and omits unrepairable gear from smith lists', function (): void {
    $equipment = Equipment::query()->findOrFail('mobile_0');
    $equipment->repairable = false;
    $equipment->save();

    $p = characters()->createDraft(8703);
    inventory()->addItem($p->tg_id, 'mobile_0');
    $cap = inventory()->findOwned($p->tg_id, 'mobile_0');
    $cap->durability = 10;
    $cap->save();

    $p->silver = 10_000;
    $p->gold = 10_000;
    $p->save();

    $repair = inventory()->repair($p, $cap->id);

    expect($repair->ok)->toBeFalse()
        ->and($repair->error)->toBe(__('errors.cannot_repair'))
        ->and(inventory()->damagedList($p->tg_id))->toHaveCount(0)
        ->and(inventory()->repairAll($p)->ok)->toBeFalse();

    $cap->refresh();
    expect($cap->durability)->toBe(10);
});

it('repairs only normal-tier gear on repair all', function (): void {
    $p = characters()->createDraft(8704);
    inventory()->addItem($p->tg_id, 'mobile_0');
    inventory()->addItem($p->tg_id, 'knife_3');

    $cap = inventory()->findOwned($p->tg_id, 'mobile_0');
    $vip = inventory()->findOwned($p->tg_id, 'knife_3');
    $cap->durability = 10;
    $cap->save();
    $vip->durability = 10;
    $vip->save();

    expect(inventory()->damagedList($p->tg_id))->toHaveCount(1)
        ->and(inventory()->damagedList($p->tg_id)->first()->item_id)->toBe('mobile_0')
        ->and(inventory()->damagedVipList($p->tg_id))->toHaveCount(1);

    $goldCost = inventory()->repairAllGoldCost($p);
    $p->gold = $goldCost;
    $p->save();

    $ok = inventory()->repairAll($p);
    expect($ok->ok)->toBeTrue();

    $cap->refresh();
    $vip->refresh();
    expect($cap->durability)->toBe($cap->max_durability)
        ->and($vip->durability)->toBe(10);
});

it('stores null durability for jewelry and potions', function (): void {
    $p = characters()->createDraft(8705);
    $ring = inventory()->addItem($p->tg_id, 'focus_0');
    $potion = inventory()->addItem($p->tg_id, 'heal_0');

    expect($ring->max_durability)->toBeNull()
        ->and($ring->durability)->toBeNull()
        ->and($potion->max_durability)->toBeNull()
        ->and($potion->durability)->toBeNull()
        ->and(shopCatalog()->findItem('focus_0')->maxDurability)->toBeNull()
        ->and(shopCatalog()->findItem('heal_0')->maxDurability)->toBeNull();
});

it('does not wear unequipped inventory rows after a fight', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8706));
    inventory()->addItem($p->tg_id, 'mobile_0');

    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $bag = inventory()->findOwned($p->tg_id, 'mobile_0');
    $knuckles->durability = 10;
    $knuckles->save();
    $bag->durability = 10;
    $bag->save();

    inventory()->applyFightWearAfterWin($p, 0);

    $knuckles->refresh();
    $bag->refresh();
    $loss = Equipment::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;

    expect($knuckles->durability)->toBe(10 - $loss)
        ->and($bag->durability)->toBe(10)
        ->and($bag->is_equipped)->toBeFalse();
});

it('counts pierce wear only from pierced block hits', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8707));
    $p->username = 'PierceUi';
    $p->save();

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = FightPlayerAttackEnum::HEAD;
    $fight->player_defend = null;
    $fight->step = FightStepEnum::DEFEND;
    $fight->pierce_count = 0;
    $fight->save();

    // stance DEFEND, enemyAtk HEAD, enemyDef HEAD, weapon roll, pierce hit, pierce mult
    fakeRandom([0.0, 0.0, 0.0, 0.0, 0.0, 0.5]);
    app(App\Services\Fight\FightRoundService::class)->resolve($p);
    $fight->refresh();
    expect($fight->pierce_count)->toBe(1);

    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = FightPlayerAttackEnum::HEAD;
    $fight->player_defend = null;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    // stance DEFEND, enemyAtk HEAD, enemyDef LEGS (open hit), weapon roll, dodge miss, variance
    fakeRandom([0.0, 0.0, 0.75, 0.0, 0.99, 0.5]);
    app(App\Services\Fight\FightRoundService::class)->resolve(characters()->findByTgId($p->tg_id));
    $fight->refresh();
    expect($fight->pierce_count)->toBe(1);

    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 20;
    $knuckles->save();

    inventory()->applyFightWearAfterWin(characters()->findByTgId($p->tg_id), $fight->pierce_count);
    $knuckles->refresh();

    $loss = Equipment::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;
    $perPierce = gameConfig()->settings()['wear']['extraLossPerPierce'];
    expect($knuckles->durability)->toBe(20 - $loss - (1 * $perPierce));
});

it('shows gear screen broken line through menu callback', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8708));
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'BrokenGear';
    $p->save();

    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 0;
    $knuckles->save();

    $update = new TelegramUpdate([
        'update_id' => 9702,
        'callback_query' => [
            'id' => 'cb-gear-1',
            'data' => 'menu:gear',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 42,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'menu',
            ],
        ],
    ]);

    app(MenuHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $broken = __('profile.gear_slot_broken', [
        'slot' => SlotEnum::RIGHT_HAND->getLabel(),
        'name' => $knuckles->item_name,
    ]);

    Http::assertSent(function ($request) use ($broken): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $data = $request->data();
        $text = '';

        if (array_key_exists('text', $data) && is_string($data['text'])) {
            $text = $data['text'];
        }

        return str_contains($text, $broken)
            && str_contains($text, 'урон 0');
    });
});
