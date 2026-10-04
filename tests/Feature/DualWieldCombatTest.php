<?php

declare(strict_types=1);

use App\Enums\Equipment\SlotEnum;
use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Services\Inventory\LoadoutService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\CombatHandler;
use Illuminate\Support\Facades\Http;

it('sets attackSlots to 2 only with dual weapons in both hands from dualWieldMinLevel', function (): void {
    $p = characters()->createDraft(8501);
    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);

    expect($p->level)->toBe(0)
        ->and(app(LoadoutService::class)->forCharacter($p)->attackSlots)->toBe(1)
        ->and(app(LoadoutService::class)->forCharacter($p)->offHandDamageMax)->toBeGreaterThan(0);

    $p->level = 1;
    $p->save();

    expect(app(LoadoutService::class)->forCharacter($p)->attackSlots)->toBe(2);
});

it('asks for second attack zone when dual-wield is active', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $p = characters()->createDraft(8502);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'DualHero';
    $p->level = 1;
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $fight->step = FightStepEnum::ATTACK;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->save();

    $update = new TelegramUpdate([
        'update_id' => 9101,
        'callback_query' => [
            'id' => 'cb-dw-1',
            'data' => 'fight:atk:HEAD',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 20,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    app(CombatHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::ATTACK_SECOND)
        ->and($fight->player_attack)->toBe(FightPlayerAttackEnum::HEAD)
        ->and($fight->player_attack_second)->toBeNull();

    $update2 = new TelegramUpdate([
        'update_id' => 9102,
        'callback_query' => [
            'id' => 'cb-dw-2',
            'data' => 'fight:atk:CHEST',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 20,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    app(CombatHandler::class)->handleCallback(
        $update2,
        new TelegramResponder(app(TelegramClient::class), $update2),
    );

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::DEFEND)
        ->and($fight->player_attack_second)->toBe(FightPlayerAttackEnum::CHEST);
});

it('resolves two player hits with split hand damage', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.99, 0.99, 0.99, 0.99, 0.99]));

    $p = characters()->createDraft(8503);
    $p->level = 1;
    $p->username = 'DualDmg';
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);

    $loadout = app(LoadoutService::class)->forCharacter($p);
    $knuckles = shopCatalog()->findItem(shopCatalog()->starterKnucklesId());
    $off = shopCatalog()->findItem('knife_4');
    expect($loadout->attackSlots)->toBe(2)
        ->and($loadout->mainHandDamageMin)->toBe($knuckles->weaponDamageMin)
        ->and($loadout->mainHandDamageMax)->toBe($knuckles->weaponDamageMax)
        ->and($loadout->offHandDamageMin)->toBe($off->weaponDamageMin)
        ->and($loadout->offHandDamageMax)->toBe($off->weaponDamageMax);

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $fight->enemy = $enemy;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = FightPlayerAttackEnum::HEAD;
    $fight->player_attack_second = FightPlayerAttackEnum::LEGS;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    $enemyHpBefore = (int) $fight->enemy['current_hp'];
    expect($fight->player_attack_second)->toBe(FightPlayerAttackEnum::LEGS);

    $outcome = app(App\Services\Fight\FightRoundService::class)->resolve($p);

    expect($outcome->kind)->not->toBe('missing');
    $fight->refresh();

    expect($fight->log)->toHaveCount(3)
        ->and((int) $fight->enemy['current_hp'])->toBeLessThan($enemyHpBefore);
});

it('keeps single attack step without left hand even at level 1', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $p = characters()->createDraft(8504);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'OneHand';
    $p->level = 5;
    $p->save();

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $fight->step = FightStepEnum::ATTACK;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->save();

    $update = new TelegramUpdate([
        'update_id' => 9103,
        'callback_query' => [
            'id' => 'cb-dw-3',
            'data' => 'fight:atk:BELLY',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 21,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    app(CombatHandler::class)->handleCallback(
        $update,
        new TelegramResponder(app(TelegramClient::class), $update),
    );

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::DEFEND)
        ->and($fight->player_attack)->toBe(FightPlayerAttackEnum::BELLY)
        ->and($fight->player_attack_second)->toBeNull();
});

it('rejects left hand weapon while single-hand weapon is equipped', function (): void {
    $p = characters()->createDraft(8505);
    inventory()->addItem($p->tg_id, 'axe_0');
    $axe = inventory()->findOwned($p->tg_id, 'axe_0');
    $eq = inventory()->equip($p, $axe->id);
    expect($eq->ok)->toBeTrue();
    $p = $eq->character;

    inventory()->addItem($p->tg_id, 'knife_4');
    $knife = inventory()->findOwned($p->tg_id, 'knife_4');
    $fail = inventory()->equipToSlot($p, $knife->id, SlotEnum::LEFT_HAND);

    expect($fail->ok)->toBeFalse()
        ->and($fail->error)->toBe(__('errors.cannot_dual_with_single_hand'))
        ->and(app(LoadoutService::class)->forCharacter($p)->attackSlots)->toBe(1);
});

it('allows axe with shield for one attack and two blocks', function (): void {
    $p = characters()->createDraft(8506);
    inventory()->addItem($p->tg_id, 'axe_0');
    inventory()->addItem($p->tg_id, 'heavy_1');

    $axe = inventory()->findOwned($p->tg_id, 'axe_0');
    $eqAxe = inventory()->equip($p, $axe->id);
    expect($eqAxe->ok)->toBeTrue();
    $p = $eqAxe->character;

    $shield = inventory()->findOwned($p->tg_id, 'heavy_1');
    $eqShield = inventory()->equip($p, $shield->id);
    expect($eqShield->ok)->toBeTrue();

    $loadout = app(LoadoutService::class)->forCharacter($eqShield->character);
    expect($loadout->attackSlots)->toBe(1)
        ->and($loadout->blockSlots)->toBe(2);
});

it('keeps one attack when knife is worn with shield', function (): void {
    $p = characters()->createDraft(8507);
    $p = equipItemToSlot($p, 'knife_4', SlotEnum::RIGHT_HAND);
    inventory()->addItem($p->tg_id, 'heavy_1');
    $shield = inventory()->findOwned($p->tg_id, 'heavy_1');
    $eq = inventory()->equip($p, $shield->id);
    expect($eq->ok)->toBeTrue();

    $loadout = app(LoadoutService::class)->forCharacter($eq->character);
    expect($loadout->attackSlots)->toBe(1)
        ->and($loadout->blockSlots)->toBe(2)
        ->and($loadout->row(SlotEnum::RIGHT_HAND))->not->toBeNull()
        ->and($loadout->row(SlotEnum::LEFT_HAND))->toBeNull();
});

it('unequips left hand dual weapon when single-hand is equipped', function (): void {
    $p = characters()->createDraft(8508);
    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);

    inventory()->addItem($p->tg_id, 'axe_0');
    $axe = inventory()->findOwned($p->tg_id, 'axe_0');
    $eq = inventory()->equip($p, $axe->id);
    expect($eq->ok)->toBeTrue();

    $knife = inventory()->findOwned($p->tg_id, 'knife_4');
    $knife->refresh();
    expect($knife->is_equipped)->toBeFalse()
        ->and(app(LoadoutService::class)->forCharacter($eq->character)->attackSlots)->toBe(1);
});
