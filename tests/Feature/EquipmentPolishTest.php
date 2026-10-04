<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\Equipment;
use App\Services\Inventory\GemService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

it('adds extra durability loss per pierce on win', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8601));
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 10;
    $knuckles->save();

    inventory()->applyFightWearAfterWin($p, 3);
    $knuckles->refresh();

    $loss = Equipment::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;
    $perPierce = gameConfig()->settings()['wear']['extraLossPerPierce'];

    expect($knuckles->durability)->toBe(10 - $loss - (3 * $perPierce));
});

it('buys gem insurance and skips break on lose', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.0]));

    $p = characters()->createDraft(8602);
    $cost = app(App\Services\Shop\GemCatalog::class)->insuranceGold();
    $p->gold = $cost;
    $p->gem_pouch = ['gem_ruby_t1' => 1];
    $p->save();

    $buy = app(GemService::class)->buyInsurance($p);
    expect($buy->ok)->toBeTrue()
        ->and($buy->character->gem_insurance_charges)->toBe(1)
        ->and($buy->character->gold)->toBe(0);

    $p = giveAndEquipStarterKnuckles($buy->character);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_ruby_t1');
    expect($socket->ok)->toBeTrue();

    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();
    $p = characters()->findByTgId($p->tg_id);

    expect($broken)->toBe([])
        ->and(app(GemService::class)->socketedGemIds($knuckles))->toBe(['gem_ruby_t1'])
        ->and($p->gem_insurance_charges)->toBe(0);
});

it('reduces break chance for premium characters', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.30]));

    $p = characters()->createDraft(8603);
    $p->gem_pouch = ['gem_sapphire_t1' => 1];
    $p->premium_until = now()->addDay();
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = inventory()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = app(GemService::class)->socket($p, $knuckles->id, 'gem_sapphire_t1');
    $broken = app(GemService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    // base 40%, premium -15% => 25%; roll 0.30*100=30 >= 25 → keep
    expect($broken)->toBe([])
        ->and(app(GemService::class)->socketedGemIds($knuckles))->toBe(['gem_sapphire_t1']);
});

it('allows any gem type in an open socket', function (): void {
    $p = characters()->createDraft(8605);
    $p->gem_pouch = ['gem_sapphire_t1' => 1];
    $p->save();

    inventory()->addItem($p->tg_id, 'knife_4');
    $knife = inventory()->findOwned($p->tg_id, 'knife_4');

    expect(app(GemService::class)->socket($p, $knife->id, 'gem_sapphire_t1')->ok)->toBeTrue();
});

it('grants starter gem on shop quest finish', function (): void {
    $ob = gameConfig()->onboarding();
    $p = onboarding()->ensurePlayer(8606);
    $p = onboarding()->setNick($p, 'GemStart')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[0])->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;
    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());

    expect($res->ok)->toBeTrue()
        ->and($res->character->onboarding_step)->toBe(OnboardingStepEnum::DONE)
        ->and(app(GemService::class)->pouch($res->character))->toHaveKey($ob['starterGemId']);
});
