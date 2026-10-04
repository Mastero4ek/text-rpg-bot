<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Inventory;
use App\Services\Inventory\LoadoutService;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Handlers\FightHandler;
use Illuminate\Support\Facades\Http;

it('maps armor by zone and sets two block slots with shield', function (): void {
    $p = characters()->createDraft(8101);
    inventory()->addItem($p->tg_id, 'mobile_0');
    inventory()->addItem($p->tg_id, 'heavy_0');
    inventory()->addItem($p->tg_id, 'mobile_2');
    inventory()->addItem($p->tg_id, 'mobile_1');
    inventory()->addItem($p->tg_id, 'heavy_1');

    foreach (['mobile_0', 'heavy_0', 'mobile_2', 'mobile_1', 'heavy_1'] as $itemId) {
        $row = inventory()->findOwned($p->tg_id, $itemId);
        $res = inventory()->equip($p, $row->id);
        expect($res->ok)->toBeTrue();
        $p = $res->character;
    }

    $loadout = app(LoadoutService::class)->forCharacter($p);

    expect($loadout->blockSlots)->toBe(2)
        ->and($loadout->armorByZone['HEAD'])->toBe(shopCatalog()->findItem('mobile_0')->armor)
        ->and($loadout->armorByZone['CHEST'])->toBe(shopCatalog()->findItem('heavy_0')->armor)
        ->and($loadout->armorByZone['BELLY'])->toBe(shopCatalog()->findItem('mobile_2')->armor)
        ->and($loadout->armorByZone['LEGS'])->toBe(shopCatalog()->findItem('mobile_1')->armor)
        ->and($loadout->armor)->toBe(
            $loadout->armorByZone['HEAD']
            + $loadout->armorByZone['CHEST']
            + $loadout->armorByZone['BELLY']
            + $loadout->armorByZone['LEGS']
        );
});

it('unequips left hand when shield is equipped and the reverse', function (): void {
    $p = characters()->createDraft(8102);
    inventory()->addItem($p->tg_id, 'heavy_1');

    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);
    $knife = inventory()->findOwned($p->tg_id, 'knife_4');
    expect($knife->is_equipped)->toBeTrue()
        ->and($knife->slot)->toBe(SlotEnum::LEFT_HAND);

    $shield = inventory()->findOwned($p->tg_id, 'heavy_1');
    $eqShield = inventory()->equip($p, $shield->id);
    expect($eqShield->ok)->toBeTrue();
    $knife->refresh();
    $shield->refresh();
    expect($shield->is_equipped)->toBeTrue()
        ->and($knife->is_equipped)->toBeFalse();

    $p = equipItemToSlot($eqShield->character, 'knife_4', SlotEnum::LEFT_HAND);
    $knife->refresh();
    $shield->refresh();
    expect($knife->is_equipped)->toBeTrue()
        ->and($shield->is_equipped)->toBeFalse();
});

it('sums left hand weapon damage and jewelry mf into loadout', function (): void {
    $p = characters()->createDraft(8103);
    $p->level = 1;
    $p->save();

    inventory()->addItem($p->tg_id, 'focus_0');
    inventory()->addItem($p->tg_id, 'vital_0');

    $baseMin = app(LoadoutService::class)->forCharacter($p)->weaponDamageMin;
    $baseMax = app(LoadoutService::class)->forCharacter($p)->weaponDamageMax;

    $p = equipItemToSlot($p, 'knife_4', SlotEnum::LEFT_HAND);

    foreach (['focus_0', 'vital_0'] as $itemId) {
        $row = inventory()->findOwned($p->tg_id, $itemId);
        $res = inventory()->equip($p, $row->id);
        expect($res->ok)->toBeTrue();
        $p = $res->character;
    }

    $loadout = app(LoadoutService::class)->forCharacter($p);
    $knife = shopCatalog()->findItem('knife_4');
    $ring = shopCatalog()->findItem('focus_0');
    $amulet = shopCatalog()->findItem('vital_0');

    expect($loadout->weaponDamageMin)->toBe($baseMin + $knife->weaponDamageMin)
        ->and($loadout->weaponDamageMax)->toBe($baseMax + $knife->weaponDamageMax)
        ->and($loadout->mf->dodge)->toBeGreaterThanOrEqual($ring->mf->dodge)
        ->and($loadout->statBonus)->toBeGreaterThanOrEqual($amulet->statBonus)
        ->and($loadout->blockSlots)->toBe(1);
});

it('sells new doll wearables from shop', function (): void {
    $p = characters()->createDraft(8104);
    $p->silver = 500;
    $p->save();

    foreach (['mobile_2', 'mobile_3', 'heavy_1', 'focus_0', 'charm_0', 'vital_0', 'knife_4'] as $itemId) {
        $buy = shopService()->buyGear($p->tg_id, $itemId);

        if ($itemId === 'knife_4') {
            $buy = shopService()->buyWeapon($p->tg_id, $itemId);
        }

        expect($buy->ok)->toBeTrue("failed buying {$itemId}")
            ->and(inventory()->owns($p->tg_id, $itemId))->toBeTrue();
    }

    expect(shopCatalog()->isEquippable('heavy_1'))->toBeTrue()
        ->and(shopCatalog()->isEquippable('focus_0'))->toBeTrue()
        ->and(shopCatalog()->isShopJewelry('vital_0'))->toBeTrue();
});

it('asks for second defend zone when shield is equipped', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(8105);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'ShieldHero';
    $p->save();

    inventory()->addItem($p->tg_id, 'heavy_1');
    $shield = inventory()->findOwned($p->tg_id, 'heavy_1');
    $eq = inventory()->equip($p, $shield->id);
    expect($eq->ok)->toBeTrue();
    $p = $eq->character;

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $fight->step = FightStepEnum::DEFEND;
    $fight->player_stance = StanceEnum::DEFEND;
    $fight->player_attack = App\Enums\Fight\PlayerAttackEnum::HEAD;
    $fight->save();

    $update = new TelegramUpdate([
        'update_id' => 9001,
        'callback_query' => [
            'id' => 'cb1',
            'data' => 'fight:def:HEAD',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    $responder = new TelegramResponder(app(TelegramClient::class), $update);

    app(FightHandler::class)->handleCallback($update, $responder);

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::DEFEND_SECOND)
        ->and($fight->player_defend)->toBe(ZoneEnum::HEAD)
        ->and($fight->player_defend_second)->toBeNull();

    $update2 = new TelegramUpdate([
        'update_id' => 9002,
        'callback_query' => [
            'id' => 'cb2',
            'data' => 'fight:def:LEGS',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    $responder2 = new TelegramResponder(app(TelegramClient::class), $update2);

    app(FightHandler::class)->handleCallback($update2, $responder2);

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::STANCE)
        ->and($fight->player_defend)->toBeNull()
        ->and($fight->player_defend_second)->toBeNull();
});

it('resolves after one defend zone without shield', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $p = characters()->createDraft(8106);
    $p->onboarding_step = OnboardingStepEnum::DONE;
    $p->username = 'NoShield';
    $p->save();

    $fight = fights()->createTraining($p, combat()->makeWoodenSoldier());
    $fight->step = FightStepEnum::DEFEND;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = App\Enums\Fight\PlayerAttackEnum::CHEST;
    $fight->save();

    $update = new TelegramUpdate([
        'update_id' => 9003,
        'callback_query' => [
            'id' => 'cb3',
            'data' => 'fight:def:BELLY',
            'from' => ['id' => $p->tg_id, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 11,
                'chat' => ['id' => $p->tg_id, 'type' => 'private'],
                'text' => 'fight',
            ],
        ],
    ]);

    $responder = new TelegramResponder(app(TelegramClient::class), $update);

    app(FightHandler::class)->handleCallback($update, $responder);

    $fight->refresh();
    expect($fight->step)->toBe(FightStepEnum::STANCE)
        ->and($fight->player_defend)->toBeNull();
});

it('keeps gloves and rings equippable in gameplay slots', function (): void {
    $p = characters()->createDraft(8107);
    inventory()->addItem($p->tg_id, 'mobile_3');
    inventory()->addItem($p->tg_id, 'charm_0');

    $gloves = inventory()->findOwned($p->tg_id, 'mobile_3');
    $ring = inventory()->findOwned($p->tg_id, 'charm_0');

    expect(inventory()->equip($p, $gloves->id)->ok)->toBeTrue();
    $p = characters()->findByTgId($p->tg_id);
    expect(inventory()->equip($p, $ring->id)->ok)->toBeTrue();

    $loadout = app(LoadoutService::class)->forCharacter(characters()->findByTgId($p->tg_id));
    expect($loadout->row(SlotEnum::GLOVES))->toBeInstanceOf(Inventory::class)
        ->and($loadout->row(SlotEnum::RING_2))->toBeInstanceOf(Inventory::class);
});
