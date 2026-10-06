<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Services\Bag\BagService;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;

it('adds extra durability loss per pierce on win', function (): void {
    $p = giveAndEquipStarterKnuckles(characters()->createDraft(8601));
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $knuckles->durability = 10;
    $knuckles->save();

    loadout()->applyFightWearAfterWin($p, 3);
    $knuckles->refresh();

    $loss = BackpackCatalog::query()->findOrFail(shopCatalog()->starterKnucklesId())->durability_loss_per_fight;
    $perPierce = gameConfig()->settings()['wear']['extraLossPerPierce'];

    expect($knuckles->durability)->toBe(10 - $loss - (3 * $perPierce));
});

it('reduces break chance for premium characters', function (): void {
    $this->app->instance(RandomSourceContract::class, new FakeRandomSource([0.30]));

    $p = characters()->createDraft(8603);
    $p->premium_until = now()->addDay();
    $p->save();
    $p = grantGem($p, 'sapphire_0', 1);

    $p = giveAndEquipStarterKnuckles($p);
    $knuckles = backpack()->findOwned($p->tg_id, shopCatalog()->starterKnucklesId());
    $socket = socketGem($p, $knuckles, 'sapphire_0');
    $broken = app(BagService::class)->breakSocketedOnLose($socket->character);
    $knuckles->refresh();

    // base 40%, premium -15% => 25%; roll 0.30*100=30 >= 25 → keep
    expect($broken)->toBe([])
        ->and(app(BagService::class)->socketedGemIds($knuckles))->toBe(['sapphire_0'])
        ->and(app(BagService::class)->socketedInstances($knuckles)->first()->durability)->toBe(10);
});

it('allows any gem type in an open socket', function (): void {
    $p = characters()->createDraft(8605);
    $p = grantGem($p, 'sapphire_0', 1);

    backpack()->addItem($p->tg_id, 'knife_0');
    $knife = backpack()->findOwned($p->tg_id, 'knife_0');

    expect(socketGem($p, $knife, 'sapphire_0')->ok)->toBeTrue();
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
        ->and($ob['starterGemId'])->toBe('ruby_0')
        ->and(hasLooseGem($res->character, 'ruby_0'))->toBeTrue()
        ->and(looseGem($res->character, 'ruby_0')->durability)->toBe(10);
});
