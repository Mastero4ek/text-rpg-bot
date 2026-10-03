<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;

it('ensurePlayer creates once', function (): void {
    $a = onboarding()->ensurePlayer(3001);
    $b = onboarding()->ensurePlayer(3001);

    expect($a->tg_id)->toBe($b->tg_id)
        ->and($a->onboarding_step)->toBe(OnboardingStepEnum::NICK);
});

it('setNick validates and uniqueness', function (): void {
    $p1 = onboarding()->ensurePlayer(3002);

    expect(onboarding()->setNick($p1, 'ab')->ok)->toBeFalse();

    $ok = onboarding()->setNick($p1, 'HeroOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->onboarding_step)->toBe(OnboardingStepEnum::CITY);

    $p2 = onboarding()->ensurePlayer(3052);
    expect(onboarding()->setNick($p2, 'HeroOne')->ok)->toBeFalse();
});

it('setLocation only from list', function (): void {
    $p = onboarding()->ensurePlayer(3003);
    $p = onboarding()->setNick($p, 'CityGuy')->character;

    expect(onboarding()->setLocation($p, 'Nowhere')->ok)->toBeFalse();

    $city = onboarding()->cities()[0];
    $res = onboarding()->setLocation($p, $city);

    expect($res->ok)->toBeTrue()
        ->and($res->character->location)->toBe($city)
        ->and($res->character->onboarding_step)->toBe(OnboardingStepEnum::INTRO);
});

it('tutorial fight persists and win lose', function (): void {
    $ob = gameConfig()->onboarding();
    $p = onboarding()->ensurePlayer(3004);
    $p = onboarding()->setNick($p, 'Fighter')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[0])->character;

    $game = onboarding()->startTutorialFight($p);
    expect($game->tutorial)->toBeTrue()
        ->and(fights()->enemy($game)->level)->toBe(0)
        ->and($p->fresh()->onboarding_step)->toBe(OnboardingStepEnum::TUTORIAL_FIGHT)
        ->and(fights()->exists($p->tg_id))->toBeTrue();

    $p->current_hp = 1;
    $p->save();
    $p = onboarding()->onTutorialLose($p);
    expect($p->current_hp)->toBe(characters()->maxHp($p));

    $p = onboarding()->onTutorialWin($p);
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_STATS)
        ->and($p->silver)->toBe($ob['start']['silver'] + $ob['rewards']['tutorialWin']['silver']);
});

it('stats equip shop free club full path', function (): void {
    $ob = gameConfig()->onboarding();
    $p = onboarding()->ensurePlayer(3005);
    $p = onboarding()->setNick($p, 'Graduate')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[1])->character;
    $p = onboarding()->onTutorialWin($p);

    expect(onboarding()->finishStatsQuest($p)->ok)->toBeFalse();

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $res = onboarding()->finishStatsQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_EQUIP);

    $res = onboarding()->finishEquipQuest($p);
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    expect($p->armor_id)->toBe(shopCatalog()->mailShirtId())
        ->and($p->onboarding_step)->toBe(OnboardingStepEnum::QUEST_SHOP);

    $res = onboarding()->finishShopQuestClaim($p, shopCatalog()->freeTrainerItemId());
    expect($res->ok)->toBeTrue();
    $p = $res->character;
    expect($p->onboarding_step)->toBe(OnboardingStepEnum::DONE)
        ->and($p->level)->toBe($ob['graduateLevel'])
        ->and($p->weapon_id)->toBe(shopCatalog()->freeTrainerItemId());
});

it('stepHint covers known steps', function (): void {
    expect(onboarding()->stepHint(OnboardingStepEnum::NICK->value))->not->toBeEmpty()
        ->and(onboarding()->stepHint('unknown_step'))->not->toBeEmpty();
});

it('finishShopQuestClaim rejects non trainer weapon', function (): void {
    $p = onboarding()->ensurePlayer(3006);
    $p = onboarding()->setNick($p, 'ShopBad')->character;
    $p = onboarding()->setLocation($p, onboarding()->cities()[0])->character;
    $p = onboarding()->onTutorialWin($p);

    while ($p->stat_points > 0) {
        $p = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->character;
    }

    $p = onboarding()->finishStatsQuest($p)->character;
    $p = onboarding()->finishEquipQuest($p)->character;

    expect(onboarding()->finishShopQuestClaim($p, 'train_knife')->ok)->toBeFalse();
});
