<?php

declare(strict_types=1);

use App\Models\City;
use App\Support\Demo\DemoMode;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'bot.demo.enabled' => true,
        'bot.demo.max_level' => 3,
    ]);
});

it('pays zero for buyer sell at demo max level and still removes the item', function (): void {
    $p = placeInCity(characters()->createDraft(9210), City::KEY_ANKRAT);
    $p->level = 3;
    $p->silver = 40;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = $knife->max_durability;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(0);

    $sold = buyerService()->sellBackpackItem($p->fresh(), $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(40)
        ->and(backpack()->owns($p->tg_id, 'knife_0'))->toBeFalse();
});

it('pays normal buyer sell below demo max level', function (): void {
    $p = placeInCity(characters()->createDraft(9211), City::KEY_ANKRAT);
    $p->level = 2;
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 20;
    $knife->max_durability = 40;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(5);

    $sold = buyerService()->sellBackpackItem($p->fresh(), $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(5);
});

it('pays zero for bag sell at demo max level', function (): void {
    $p = placeInCity(characters()->createDraft(9212), City::KEY_ANKRAT);
    $p->level = 3;
    $p->silver = 12;
    $p->save();

    bag()->addPotion($p->tg_id, 'heal_0');
    $potion = bag()->loosePotions($p)->first();

    expect($potion)->not->toBeNull()
        ->and(bag()->sellPayout($potion))->toBe(0);

    $sold = buyerService()->sellBagItem($p->fresh(), $potion->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(12);
});

it('pays normal sell when demo mode is off even at level three', function (): void {
    config(['bot.demo.enabled' => false]);

    $p = placeInCity(characters()->createDraft(9213), City::KEY_ANKRAT);
    $p->level = 3;
    $p->silver = 0;
    $p->save();

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');
    $knife->durability = 20;
    $knife->max_durability = 40;
    $knife->save();

    expect(backpack()->sellPayout($knife))->toBe(5);

    $sold = buyerService()->sellBackpackItem($p->fresh(), $knife->id);

    expect($sold->ok)->toBeTrue()
        ->and($sold->character->silver)->toBe(5);
});

it('appends buyer no-pay notice only when demo capped', function (): void {
    $capped = placeInCity(characters()->createDraft(9214), City::KEY_ANKRAT);
    $capped->level = 3;
    $capped->save();

    $below = placeInCity(characters()->createDraft(9215), City::KEY_ANKRAT);
    $below->level = 2;
    $below->save();

    $base = 'deal';

    expect(DemoMode::withBuyerNoPayNotice($capped, $base))
        ->toBe($base . "\n\n" . __('profile.demo_buyer_no_pay'))
        ->and(DemoMode::withBuyerNoPayNotice($below, $base))
        ->toBe($base);
});

it('shows buyer no-pay notice on sell_yes telegram panel at demo max level', function (): void {
    Bus::fake();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $player = cityDone(9219, 'DemoSellTg');
    $player->level = 3;
    $player->silver = 25;
    $player->save();

    backpack()->addItem($player->tg_id, 'knife_0');
    $knife = backpack()->findOwned($player->tg_id, 'knife_0');
    $knife->durability = $knife->max_durability;
    $knife->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer:sell_yes:bp:' . $knife->id);

    expect(backpack()->owns($player->tg_id, 'knife_0'))->toBeFalse()
        ->and(characters()->findByTgId($player->tg_id)->silver)->toBe(25);

    assertCityEditHas(__('profile.demo_buyer_no_pay'));
    assertCityEditHas(mb_trim(__('telegram.npc.buyer.sold', [
        'name' => $knife->item_name,
        'price' => 0,
        'mark' => '🪙',
    ])));
});
